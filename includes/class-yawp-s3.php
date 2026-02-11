<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class YAWP_S3 {

    private $access_key;
    private $secret_key;
    private $region;
    private $bucket;
    private $service = 's3';

    const PART_SIZE = 10485760; // 10 MB
    const MULTIPART_THRESHOLD = 104857600; // 100 MB

    public function __construct( $access_key, $secret_key, $region, $bucket ) {
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
        $this->region     = $region;
        $this->bucket     = $bucket;
    }

    private function host() {
        return $this->bucket . '.s3.' . $this->region . '.amazonaws.com';
    }

    private function base_url() {
        return 'https://' . $this->host();
    }

    /**
     * Upload a file to S3 — routes to single PUT or multipart based on size.
     */
    public function upload( $key, $file_path, $retention_days = 90 ) {
        $size = filesize( $file_path );
        if ( $size >= self::MULTIPART_THRESHOLD ) {
            return $this->multipart_upload( $key, $file_path, $retention_days );
        }
        return $this->put_object( $key, $file_path, $retention_days );
    }

    /**
     * Single PUT upload with Object Lock headers.
     */
    public function put_object( $key, $file_path, $retention_days = 90 ) {
        $size     = filesize( $file_path );
        $sha256   = hash_file( 'sha256', $file_path );
        $retain   = gmdate( 'Y-m-d\TH:i:s\Z', time() + ( $retention_days * 86400 ) );
        $url      = $this->base_url() . '/' . $this->encode_key( $key );

        $headers = [
            'Content-Length'                    => $size,
            'Content-Type'                      => 'application/gzip',
            'x-amz-content-sha256'              => $sha256,
            'x-amz-object-lock-mode'            => 'COMPLIANCE',
            'x-amz-object-lock-retain-until-date' => $retain,
        ];

        $signed = $this->sign_request( 'PUT', '/' . $this->encode_key( $key ), $headers, $sha256 );

        $fh = fopen( $file_path, 'rb' );
        if ( ! $fh ) {
            return new WP_Error( 'yawp_s3', 'Cannot open file for upload.' );
        }

        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => $size,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $this->format_headers( $signed ),
            CURLOPT_TIMEOUT        => 600,
        ]);

        $response = curl_exec( $ch );
        $code     = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $error    = curl_error( $ch );
        curl_close( $ch );
        fclose( $fh );

        if ( $error ) {
            return new WP_Error( 'yawp_s3', 'cURL error: ' . $error );
        }
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'yawp_s3', "S3 PUT failed (HTTP {$code}): " . $this->parse_s3_error( $response ) );
        }
        return true;
    }

    /**
     * Multipart upload for files >= 100 MB.
     */
    public function multipart_upload( $key, $file_path, $retention_days = 90 ) {
        $upload_id = $this->initiate_multipart( $key, $retention_days );
        if ( is_wp_error( $upload_id ) ) {
            return $upload_id;
        }

        $fh   = fopen( $file_path, 'rb' );
        $parts = [];
        $part_number = 1;

        try {
            while ( ! feof( $fh ) ) {
                $data = fread( $fh, self::PART_SIZE );
                if ( $data === false || strlen( $data ) === 0 ) {
                    break;
                }

                $etag = $this->upload_part( $key, $upload_id, $part_number, $data );
                if ( is_wp_error( $etag ) ) {
                    $this->abort_multipart( $key, $upload_id );
                    fclose( $fh );
                    return $etag;
                }

                $parts[] = [
                    'PartNumber' => $part_number,
                    'ETag'       => $etag,
                ];
                $part_number++;
            }
            fclose( $fh );
        } catch ( \Throwable $e ) {
            fclose( $fh );
            $this->abort_multipart( $key, $upload_id );
            return new WP_Error( 'yawp_s3', 'Multipart upload error: ' . $e->getMessage() );
        }

        return $this->complete_multipart( $key, $upload_id, $parts );
    }

    private function initiate_multipart( $key, $retention_days ) {
        $retain = gmdate( 'Y-m-d\TH:i:s\Z', time() + ( $retention_days * 86400 ) );
        $path   = '/' . $this->encode_key( $key ) . '?uploads';
        $url    = $this->base_url() . '/' . $this->encode_key( $key ) . '?uploads';

        $headers = [
            'Content-Type'                        => 'application/gzip',
            'x-amz-content-sha256'                => hash( 'sha256', '' ),
            'x-amz-object-lock-mode'              => 'COMPLIANCE',
            'x-amz-object-lock-retain-until-date' => $retain,
        ];

        $signed = $this->sign_request( 'POST', $path, $headers, hash( 'sha256', '' ) );

        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $this->format_headers( $signed ),
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec( $ch );
        $code     = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $error    = curl_error( $ch );
        curl_close( $ch );

        if ( $error ) {
            return new WP_Error( 'yawp_s3', 'cURL error on initiate: ' . $error );
        }
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'yawp_s3', "Initiate multipart failed (HTTP {$code}): " . $this->parse_s3_error( $response ) );
        }

        if ( preg_match( '/<UploadId>(.+?)<\/UploadId>/', $response, $m ) ) {
            return $m[1];
        }
        return new WP_Error( 'yawp_s3', 'Could not parse UploadId from S3 response.' );
    }

    private function upload_part( $key, $upload_id, $part_number, $data ) {
        $sha256 = hash( 'sha256', $data );
        $path   = '/' . $this->encode_key( $key ) . '?partNumber=' . $part_number . '&uploadId=' . urlencode( $upload_id );
        $url    = $this->base_url() . '/' . $this->encode_key( $key ) . '?partNumber=' . $part_number . '&uploadId=' . urlencode( $upload_id );

        $headers = [
            'Content-Length'       => strlen( $data ),
            'x-amz-content-sha256' => $sha256,
        ];

        $signed = $this->sign_request( 'PUT', $path, $headers, $sha256 );

        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => $this->format_headers( $signed ),
            CURLOPT_TIMEOUT        => 300,
        ]);

        $response    = curl_exec( $ch );
        $code        = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $header_size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
        $error       = curl_error( $ch );
        curl_close( $ch );

        if ( $error ) {
            return new WP_Error( 'yawp_s3', "Part {$part_number} cURL error: " . $error );
        }
        if ( $code < 200 || $code >= 300 ) {
            $body = substr( $response, $header_size );
            return new WP_Error( 'yawp_s3', "Part {$part_number} failed (HTTP {$code}): " . $this->parse_s3_error( $body ) );
        }

        $resp_headers = substr( $response, 0, $header_size );
        if ( preg_match( '/ETag:\s*"?([^"\r\n]+)"?/i', $resp_headers, $m ) ) {
            return '"' . trim( $m[1], '"' ) . '"';
        }
        return new WP_Error( 'yawp_s3', "Part {$part_number}: could not parse ETag." );
    }

    private function complete_multipart( $key, $upload_id, $parts ) {
        $xml = '<CompleteMultipartUpload>';
        foreach ( $parts as $p ) {
            $xml .= '<Part><PartNumber>' . $p['PartNumber'] . '</PartNumber><ETag>' . $p['ETag'] . '</ETag></Part>';
        }
        $xml .= '</CompleteMultipartUpload>';

        $sha256 = hash( 'sha256', $xml );
        $path   = '/' . $this->encode_key( $key ) . '?uploadId=' . urlencode( $upload_id );
        $url    = $this->base_url() . '/' . $this->encode_key( $key ) . '?uploadId=' . urlencode( $upload_id );

        $headers = [
            'Content-Length'       => strlen( $xml ),
            'Content-Type'         => 'application/xml',
            'x-amz-content-sha256' => $sha256,
        ];

        $signed = $this->sign_request( 'POST', $path, $headers, $sha256 );

        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $this->format_headers( $signed ),
            CURLOPT_TIMEOUT        => 60,
        ]);

        $response = curl_exec( $ch );
        $code     = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $error    = curl_error( $ch );
        curl_close( $ch );

        if ( $error ) {
            return new WP_Error( 'yawp_s3', 'Complete multipart cURL error: ' . $error );
        }
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'yawp_s3', "Complete multipart failed (HTTP {$code}): " . $this->parse_s3_error( $response ) );
        }
        return true;
    }

    private function abort_multipart( $key, $upload_id ) {
        $path = '/' . $this->encode_key( $key ) . '?uploadId=' . urlencode( $upload_id );
        $url  = $this->base_url() . '/' . $this->encode_key( $key ) . '?uploadId=' . urlencode( $upload_id );

        $headers = [
            'x-amz-content-sha256' => hash( 'sha256', '' ),
        ];

        $signed = $this->sign_request( 'DELETE', $path, $headers, hash( 'sha256', '' ) );

        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $this->format_headers( $signed ),
            CURLOPT_TIMEOUT        => 30,
        ]);
        curl_exec( $ch );
        curl_close( $ch );
    }

    /**
     * Test connection by issuing a HEAD request on the bucket.
     */
    public function test_connection() {
        $path = '/';
        $url  = $this->base_url() . '/';

        $headers = [
            'x-amz-content-sha256' => hash( 'sha256', '' ),
        ];

        $signed = $this->sign_request( 'HEAD', $path, $headers, hash( 'sha256', '' ) );

        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $this->format_headers( $signed ),
            CURLOPT_TIMEOUT        => 15,
        ]);

        curl_exec( $ch );
        $code  = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $error = curl_error( $ch );
        curl_close( $ch );

        if ( $error ) {
            return new WP_Error( 'yawp_s3', 'Connection failed: ' . $error );
        }
        if ( $code === 200 ) {
            return true;
        }
        if ( $code === 301 ) {
            return new WP_Error( 'yawp_s3', 'Bucket region mismatch — check your region setting.' );
        }
        if ( $code === 403 ) {
            return new WP_Error( 'yawp_s3', 'Access denied — check your credentials.' );
        }
        if ( $code === 404 ) {
            return new WP_Error( 'yawp_s3', 'Bucket not found.' );
        }
        return new WP_Error( 'yawp_s3', "Unexpected HTTP {$code} from S3." );
    }

    /**
     * Upload a JSON manifest string.
     */
    public function put_json( $key, $json_string ) {
        $sha256 = hash( 'sha256', $json_string );
        $url    = $this->base_url() . '/' . $this->encode_key( $key );

        $headers = [
            'Content-Length'       => strlen( $json_string ),
            'Content-Type'         => 'application/json',
            'x-amz-content-sha256' => $sha256,
        ];

        $signed = $this->sign_request( 'PUT', '/' . $this->encode_key( $key ), $headers, $sha256 );

        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $json_string,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $this->format_headers( $signed ),
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec( $ch );
        $code     = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $error    = curl_error( $ch );
        curl_close( $ch );

        if ( $error ) {
            return new WP_Error( 'yawp_s3', 'cURL error: ' . $error );
        }
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'yawp_s3', "S3 PUT JSON failed (HTTP {$code}): " . $this->parse_s3_error( $response ) );
        }
        return true;
    }

    // ──────────────────────────────────────────────
    // SigV4 signing
    // ──────────────────────────────────────────────

    /**
     * Sign a request and return all headers (original + auth).
     */
    private function sign_request( $method, $path, $headers, $payload_hash ) {
        $now       = gmdate( 'Ymd\THis\Z' );
        $date_short = gmdate( 'Ymd' );

        $headers['Host']       = $this->host();
        $headers['x-amz-date'] = $now;

        // Separate path and query string.
        $parts = explode( '?', $path, 2 );
        $canonical_uri = $parts[0];
        $query_string  = isset( $parts[1] ) ? $parts[1] : '';

        // Canonical query string: parameters sorted by name.
        $canonical_qs = '';
        if ( $query_string !== '' ) {
            $params = [];
            foreach ( explode( '&', $query_string ) as $param ) {
                $kv = explode( '=', $param, 2 );
                $k  = rawurlencode( urldecode( $kv[0] ) );
                $v  = isset( $kv[1] ) ? rawurlencode( urldecode( $kv[1] ) ) : '';
                $params[ $k ] = $k . '=' . $v;
            }
            ksort( $params );
            $canonical_qs = implode( '&', $params );
        }

        // Canonical headers: sorted by lowercase key.
        $canonical_headers = [];
        $signed_header_names = [];
        foreach ( $headers as $k => $v ) {
            $lk = strtolower( $k );
            $canonical_headers[ $lk ] = $lk . ':' . trim( $v );
            $signed_header_names[ $lk ] = true;
        }
        ksort( $canonical_headers );
        ksort( $signed_header_names );

        $canonical_headers_str = implode( "\n", $canonical_headers ) . "\n";
        $signed_headers        = implode( ';', array_keys( $signed_header_names ) );

        $canonical_request = implode( "\n", [
            $method,
            $canonical_uri,
            $canonical_qs,
            $canonical_headers_str,
            $signed_headers,
            $payload_hash,
        ]);

        $credential_scope = $date_short . '/' . $this->region . '/' . $this->service . '/aws4_request';

        $string_to_sign = implode( "\n", [
            'AWS4-HMAC-SHA256',
            $now,
            $credential_scope,
            hash( 'sha256', $canonical_request ),
        ]);

        $signing_key = $this->derive_signing_key( $date_short );
        $signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

        $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->access_key . '/' . $credential_scope
            . ', SignedHeaders=' . $signed_headers
            . ', Signature=' . $signature;

        return $headers;
    }

    private function derive_signing_key( $date_short ) {
        $k_date    = hash_hmac( 'sha256', $date_short, 'AWS4' . $this->secret_key, true );
        $k_region  = hash_hmac( 'sha256', $this->region, $k_date, true );
        $k_service = hash_hmac( 'sha256', $this->service, $k_region, true );
        return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function format_headers( $headers ) {
        $out = [];
        foreach ( $headers as $k => $v ) {
            $out[] = $k . ': ' . $v;
        }
        return $out;
    }

    private function encode_key( $key ) {
        return implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
    }

    private function parse_s3_error( $xml ) {
        if ( preg_match( '/<Message>(.+?)<\/Message>/s', $xml, $m ) ) {
            return $m[1];
        }
        return substr( $xml, 0, 200 );
    }
}
