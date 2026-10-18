<?php
/**
 * Removes GPS location data from images before they reach the (public) media library.
 *
 * Works in place without changing any offsets or lengths: GPS values are overwritten with
 * zero bytes or spaces, so every other piece of metadata stays valid. In particular the EXIF
 * orientation survives, which WordPress needs to rotate phone photos correctly.
 *
 * Covered: EXIF GPS IFD and XMP exif:GPS* properties in JPEG (APP1), PNG (eXIf, iTXt) and
 * WebP (EXIF, XMP chunks). Not covered: AVIF/HEIF (ISO BMFF boxes) and compressed PNG text.
 *
 * @package Exab
 */

namespace Exab;

defined( 'ABSPATH' ) || exit;

/**
 * In-place GPS scrubbing.
 */
final class Gps_Scrubber {

	/**
	 * TIFF field type => size in bytes.
	 */
	const TYPE_SIZES = array(
		1  => 1,
		2  => 1,
		3  => 2,
		4  => 4,
		5  => 8,
		6  => 1,
		7  => 1,
		8  => 2,
		9  => 4,
		10 => 8,
		11 => 4,
		12 => 8,
	);

	const TAG_GPS_IFD = 0x8825;

	/**
	 * Scrubs a file in place.
	 *
	 * @param string $path File path.
	 * @param string $mime MIME type (already verified).
	 * @return bool Whether any GPS data was found and removed.
	 */
	public static function scrub_file( string $path, string $mime ): bool {
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return false;
		}

		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $data ) {
			return false;
		}

		$removed = self::scrub( $data, $mime );
		if ( $removed ) {
			file_put_contents( $path, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $removed;
	}

	/**
	 * Scrubs image bytes in place.
	 *
	 * @param string $data Image bytes (modified in place).
	 * @param string $mime MIME type.
	 * @return bool Whether any GPS data was removed.
	 */
	public static function scrub( string &$data, string $mime ): bool {
		switch ( $mime ) {
			case 'image/jpeg':
				return self::scrub_jpeg( $data );
			case 'image/png':
				return self::scrub_png( $data );
			case 'image/webp':
				return self::scrub_webp( $data );
		}
		return false;
	}

	/**
	 * JPEG: walk the segments up to the image data, scrub APP1 Exif and XMP.
	 *
	 * @param string $data Bytes.
	 * @return bool
	 */
	private static function scrub_jpeg( string &$data ): bool {
		$len = strlen( $data );
		if ( $len < 4 || "\xFF\xD8" !== substr( $data, 0, 2 ) ) {
			return false;
		}

		$removed = false;
		$pos     = 2;
		while ( $pos + 4 <= $len ) {
			if ( "\xFF" !== $data[ $pos ] ) {
				break; // Not a marker: malformed, stop without touching anything else.
			}
			$marker = ord( $data[ $pos + 1 ] );
			if ( 0xFF === $marker ) {
				++$pos; // Fill byte.
				continue;
			}
			if ( 0xDA === $marker || 0xD9 === $marker ) {
				break; // Start of scan / end of image: no more metadata segments.
			}
			if ( ( $marker >= 0xD0 && $marker <= 0xD7 ) || 0x01 === $marker ) {
				$pos += 2; // Markers without a length.
				continue;
			}

			$seg_len = unpack( 'n', substr( $data, $pos + 2, 2 ) )[1];
			$start   = $pos + 4;
			$end     = $pos + 2 + $seg_len;
			if ( $seg_len < 2 || $end > $len ) {
				break;
			}

			if ( 0xE1 === $marker ) {
				if ( "Exif\0\0" === substr( $data, $start, 6 ) ) {
					$removed = self::scrub_tiff( $data, $start + 6, $end ) || $removed;
				} else {
					$removed = self::scrub_xmp( $data, $start, $end ) || $removed;
				}
			}
			$pos = $end;
		}
		return $removed;
	}

	/**
	 * PNG: scrub eXIf (TIFF) and uncompressed iTXt XMP chunks, then fix their CRCs.
	 *
	 * @param string $data Bytes.
	 * @return bool
	 */
	private static function scrub_png( string &$data ): bool {
		$len = strlen( $data );
		if ( $len < 8 || "\x89PNG\r\n\x1a\n" !== substr( $data, 0, 8 ) ) {
			return false;
		}

		$removed = false;
		$pos     = 8;
		while ( $pos + 12 <= $len ) {
			$chunk_len = unpack( 'N', substr( $data, $pos, 4 ) )[1];
			$type      = substr( $data, $pos + 4, 4 );
			$start     = $pos + 8;
			$end       = $start + $chunk_len;
			if ( $end + 4 > $len ) {
				break;
			}

			$changed = false;
			if ( 'eXIf' === $type ) {
				$changed = self::scrub_tiff( $data, $start, $end );
			} elseif ( 'iTXt' === $type && str_starts_with( substr( $data, $start, 18 ), "XML:com.adobe.xmp\0" ) ) {
				// Keyword, NUL, compression flag (0 = uncompressed), method, language, NUL, translated keyword, NUL, text.
				if ( "\0" === $data[ $start + 18 ] ) {
					$changed = self::scrub_xmp( $data, $start, $end );
				}
			}

			if ( $changed ) {
				$crc = crc32( substr( $data, $pos + 4, 4 + $chunk_len ) );
				$data = substr_replace( $data, pack( 'N', $crc ), $end, 4 );
				$removed = true;
			}
			if ( 'IEND' === $type ) {
				break;
			}
			$pos = $end + 4;
		}
		return $removed;
	}

	/**
	 * WebP: scrub the EXIF and XMP chunks of an extended (VP8X) file.
	 *
	 * @param string $data Bytes.
	 * @return bool
	 */
	private static function scrub_webp( string &$data ): bool {
		$len = strlen( $data );
		if ( $len < 12 || 'RIFF' !== substr( $data, 0, 4 ) || 'WEBP' !== substr( $data, 8, 4 ) ) {
			return false;
		}

		$removed = false;
		$pos     = 12;
		while ( $pos + 8 <= $len ) {
			$fourcc    = substr( $data, $pos, 4 );
			$chunk_len = unpack( 'V', substr( $data, $pos + 4, 4 ) )[1];
			$start     = $pos + 8;
			$end       = $start + $chunk_len;
			if ( $end > $len ) {
				break;
			}

			if ( 'EXIF' === $fourcc ) {
				$tiff    = "Exif\0\0" === substr( $data, $start, 6 ) ? $start + 6 : $start;
				$removed = self::scrub_tiff( $data, $tiff, $end ) || $removed;
			} elseif ( 'XMP ' === $fourcc ) {
				$removed = self::scrub_xmp( $data, $start, $end ) || $removed;
			}
			$pos = $end + ( $chunk_len % 2 ); // Chunks are padded to even length.
		}
		return $removed;
	}

	/**
	 * Empties the GPS IFD of a TIFF/EXIF block in data[$base, $limit).
	 *
	 * The GPS pointer in IFD0 stays, but points to an IFD with zero entries; the entries and
	 * every value they reference are overwritten with zero bytes.
	 *
	 * @param string $data  Bytes.
	 * @param int    $base  Offset of the TIFF header ("II*\0" or "MM\0*").
	 * @param int    $limit End of the block.
	 * @return bool Whether a non-empty GPS IFD was emptied.
	 */
	private static function scrub_tiff( string &$data, int $base, int $limit ): bool {
		if ( $limit - $base < 8 ) {
			return false;
		}
		$order = substr( $data, $base, 2 );
		if ( 'II' === $order ) {
			$u16 = 'v';
			$u32 = 'V';
		} elseif ( 'MM' === $order ) {
			$u16 = 'n';
			$u32 = 'N';
		} else {
			return false;
		}

		$read16 = static fn( int $at ) => unpack( $u16, substr( $data, $at, 2 ) )[1];
		$read32 = static fn( int $at ) => unpack( $u32, substr( $data, $at, 4 ) )[1];
		$inside = static fn( int $offset, int $size ) => $offset >= 0 && $size >= 0 && $base + $offset + $size <= $limit;

		$ifd0 = $read32( $base + 4 );
		if ( ! $inside( $ifd0, 2 ) ) {
			return false;
		}

		$gps_ifd = null;
		$count   = $read16( $base + $ifd0 );
		if ( ! $inside( $ifd0 + 2, $count * 12 ) ) {
			return false;
		}
		for ( $i = 0; $i < $count; $i++ ) {
			$entry = $base + $ifd0 + 2 + $i * 12;
			if ( self::TAG_GPS_IFD === $read16( $entry ) ) {
				$gps_ifd = $read32( $entry + 8 );
				break;
			}
		}
		if ( null === $gps_ifd || ! $inside( $gps_ifd, 2 ) ) {
			return false;
		}

		$gps_count = $read16( $base + $gps_ifd );
		if ( 0 === $gps_count || ! $inside( $gps_ifd + 2, $gps_count * 12 + 4 ) ) {
			return false;
		}

		// First zero every out-of-line value, then the entries (which hold the offsets).
		for ( $i = 0; $i < $gps_count; $i++ ) {
			$entry = $base + $gps_ifd + 2 + $i * 12;
			$type  = $read16( $entry + 2 );
			$num   = $read32( $entry + 4 );
			$size  = ( self::TYPE_SIZES[ $type ] ?? 1 ) * $num;
			if ( $size > 4 ) {
				$offset = $read32( $entry + 8 );
				if ( $inside( $offset, $size ) ) {
					$data = substr_replace( $data, str_repeat( "\0", $size ), $base + $offset, $size );
				}
			}
		}
		$data = substr_replace( $data, str_repeat( "\0", 2 + $gps_count * 12 + 4 ), $base + $gps_ifd, 2 + $gps_count * 12 + 4 );

		return true;
	}

	/**
	 * Blanks exif:GPS* properties in an XMP packet in data[$start, $end), keeping the length.
	 *
	 * @param string $data  Bytes.
	 * @param int    $start Start of the region.
	 * @param int    $end   End of the region.
	 * @return bool Whether anything was blanked.
	 */
	private static function scrub_xmp( string &$data, int $start, int $end ): bool {
		$xmp = substr( $data, $start, $end - $start );
		if ( false === strpos( $xmp, 'GPS' ) ) {
			return false;
		}

		$blank   = static fn( array $m ) => $m[1] . str_repeat( ' ', strlen( $m[2] ) ) . $m[3];
		$patterns = array(
			// Attribute form: exif:GPSLatitude="49,27.1234N".
			'/(\bexif:GPS[A-Za-z]+\s*=\s*")([^"]*)(")/',
			"/(\\bexif:GPS[A-Za-z]+\\s*=\\s*')([^']*)(')/",
			// Element form, possibly with nested rdf markup.
			'#(<exif:(GPS[A-Za-z]+)\b[^>]*>)(.*?)(</exif:\2>)#s',
		);

		$scrubbed = preg_replace_callback( $patterns[0], $blank, $xmp );
		$scrubbed = preg_replace_callback( $patterns[1], $blank, $scrubbed );
		$scrubbed = preg_replace_callback(
			$patterns[2],
			static fn( array $m ) => $m[1] . str_repeat( ' ', strlen( $m[3] ) ) . $m[4],
			$scrubbed
		);

		if ( null === $scrubbed || $scrubbed === $xmp || strlen( $scrubbed ) !== strlen( $xmp ) ) {
			return false;
		}
		$data = substr_replace( $data, $scrubbed, $start, $end - $start );
		return true;
	}
}
