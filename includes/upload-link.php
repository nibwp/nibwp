<?php

declare(strict_types=1);

/**
 * Temporary signed upload URL support.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_action('rest_api_init', callback: 'nibwp_register_upload_route');

/**
 * Register the REST endpoint used by signed upload URLs.
 */
function nibwp_register_upload_route(): void
{
    $route_namespace = 'nibwp/v1';
    $route = '/upload';

    register_rest_route($route_namespace, $route, [
        'methods' => ['POST', 'PUT'],
        'callback' => 'nibwp_handle_signed_upload',
        'permission_callback' => '__return_true',
    ]);
}

/**
 * Sign an upload-link payload.
 *
 * @param array<string, mixed> $payload Upload token payload.
 * @return string|WP_Error
 */
function nibwp_sign_upload_payload(array $payload): string|WP_Error
{
    $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return new WP_Error('upload_token_encode_failed', __('Could not encode upload token payload.', 'nibwp'));
    }

    $body = nibwp_base64url_encode($json);
    $signature = hash_hmac('sha256', $body, nibwp_upload_token_secret(), binary: true);

    return $body . '.' . nibwp_base64url_encode($signature);
}

/**
 * Verify an upload-link token and return its payload.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_verify_upload_token(string $token): array|WP_Error
{
    $parts = explode('.', $token, limit: 2);
    if (count($parts) !== 2) {
        return new WP_Error('invalid_upload_token', __('Invalid upload token.', 'nibwp'), ['status' => 401]);
    }

    [$body, $signature] = $parts;
    $expected = nibwp_base64url_encode(hash_hmac('sha256', $body, nibwp_upload_token_secret(), binary: true));
    if (!hash_equals($expected, $signature)) {
        return new WP_Error('invalid_upload_token', __('Invalid upload token signature.', 'nibwp'), ['status' => 401]);
    }

    $json = nibwp_base64url_decode($body);
    if ($json === false) {
        return new WP_Error('invalid_upload_token', __('Invalid upload token payload.', 'nibwp'), ['status' => 401]);
    }

    /** @var array<string, mixed>|null $decoded */
    $decoded = json_decode($json, associative: true);
    if (!is_array($decoded)) {
        return new WP_Error('invalid_upload_token', __('Invalid upload token payload.', 'nibwp'), ['status' => 401]);
    }

    $payload = [
        'path' => $decoded['path'] ?? null,
        'expires_at' => $decoded['expires_at'] ?? null,
        'max_bytes' => $decoded['max_bytes'] ?? null,
        'overwrite' => $decoded['overwrite'] ?? null,
        'create_directories' => $decoded['create_directories'] ?? null,
        // Links minted before Media Library registration existed carry none of
        // these keys. Reading them as "off" keeps such a link doing exactly what
        // it was issued to do, and only a signed `true` can turn registration on.
        'register_attachment' => ($decoded['register_attachment'] ?? false) === true,
        'alt_text' => is_string($decoded['alt_text'] ?? null) ? $decoded['alt_text'] : '',
        'title' => is_string($decoded['title'] ?? null) ? $decoded['title'] : '',
        'user_id' => is_int($decoded['user_id'] ?? null) ? $decoded['user_id'] : 0,
    ];

    $expires_at = (int) $payload['expires_at'];
    if ($expires_at < time()) {
        return new WP_Error('upload_token_expired', __('Upload token has expired.', 'nibwp'), ['status' => 401]);
    }

    return $payload;
}

/**
 * Handle a signed upload request.
 *
 * @return array|WP_Error
 */
function nibwp_handle_signed_upload(WP_REST_Request $request)
{
    if (!nibwp_is_enabled()) {
        return new WP_Error('nibwp_disabled', __('NIBWP abilities are disabled.', 'nibwp'), ['status' => 403]);
    }

    $token = nibwp_get_upload_token_from_request($request);
    if ($token === '') {
        return new WP_Error('missing_upload_token', __('Missing upload token.', 'nibwp'), ['status' => 401]);
    }

    $payload = nibwp_verify_upload_token($token);
    if (is_wp_error($payload)) {
        return $payload;
    }

    $destination = nibwp_prepare_upload_destination($payload);
    if (is_wp_error($destination)) {
        return $destination;
    }

    $source = nibwp_open_upload_source($request);
    if (is_wp_error($source)) {
        return $source;
    }

    $stream = $source['stream'];
    $result = $destination['overwrite']
        ? nibwp_overwrite_upload_stream(
            source: $stream,
            resolved: $destination['path'],
            max_bytes: $destination['max_bytes'],
        )
        : nibwp_create_upload_stream(
            source: $stream,
            resolved: $destination['path'],
            max_bytes: $destination['max_bytes'],
        );
    fclose($stream);

    if (is_wp_error($result)) {
        return $result;
    }

    clearstatcache(clear_realpath_cache: true, filename: $destination['path']);

    $response = [
        'path' => $destination['path'],
        'bytes_written' => $result['bytes_written'],
        'created' => $result['created'],
        'directories_created' => $destination['directories_created'],
        'size' => filesize($destination['path']),
        'source' => $source['source'],
        'filename' => $source['filename'],
    ];

    if ($payload['register_attachment'] !== true) {
        return $response;
    }

    $attachment = nibwp_register_uploaded_attachment($destination['path'], $payload, $result['created']);
    if (is_wp_error($attachment)) {
        // The bytes are on disk and may have taken a long upload to get there,
        // so the file stays. What must not happen is a success response that
        // reads as "it is in the Media Library" when it is not — the caller
        // needs the path to retry registration or clean up.
        return new WP_Error(
            'attachment_registration_failed',
            sprintf(
                /* translators: 1: path of the uploaded file, 2: the reason registration failed */
                __('The file was uploaded to %1$s but could not be registered in the Media Library: %2$s', 'nibwp'),
                $destination['path'],
                $attachment->get_error_message(),
            ),
            ['status' => 500, 'path' => $destination['path']],
        );
    }

    $response['attachment_id'] = $attachment['attachment_id'];
    $response['url'] = $attachment['url'];

    return $response;
}

/**
 * Resolve and validate the upload destination from a verified token payload.
 *
 * @param array<string, mixed> $payload Verified upload token payload.
 * @return array{path: string, max_bytes: int, overwrite: bool, directories_created: array}|WP_Error
 */
function nibwp_prepare_upload_destination(array $payload): array|WP_Error
{
    if (!is_string($payload['path']) || $payload['path'] === '') {
        return new WP_Error('invalid_upload_token', __('Upload token does not contain a valid path.', 'nibwp'), ['status' => 401]);
    }

    $resolved = nibwp_resolve_path(path: $payload['path'], must_exist: false);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    if (strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) === 'php') {
        $sandbox_error = nibwp_check_php_sandbox($resolved);
        if (is_wp_error($sandbox_error)) {
            return $sandbox_error;
        }
    }

    // Checked again here, not only when the link was minted: the link is a
    // bearer credential that outlives that request, and its creator can lose
    // upload_files in the meantime. Refusing before any directory is created or
    // byte is written means a rejected registration leaves nothing behind.
    if ($payload['register_attachment'] ?? false) {
        $attachment_error = nibwp_check_upload_attachment_target($resolved, (int) ($payload['user_id'] ?? 0));
        if (is_wp_error($attachment_error)) {
            return $attachment_error;
        }
    }

    // Same reasoning, one level up: the link writes a path of its creator's
    // choosing onto the server, which is the authority create-upload-link is
    // gated on. A link minted before that gate tightened, or by an account
    // since demoted, must not still be a filesystem write. Checked here, before
    // any directory is created or byte written, so a refusal leaves no trace.
    if (!nibwp_user_can_filesystem((int) ($payload['user_id'] ?? 0))) {
        return new WP_Error(
            'upload_link_forbidden',
            __('The account this upload link was created for may no longer write files on this server.', 'nibwp'),
            ['status' => 403],
        );
    }

    $parent_dir = dirname($resolved);
    $directories_created = [];
    if (!is_dir($parent_dir)) {
        if ($payload['create_directories'] !== true) {
            /* translators: %s: directory path */
            return new WP_Error('directory_not_found', sprintf(__('Parent directory does not exist: %s', 'nibwp'), $parent_dir));
        }
        $directories_created = nibwp_ensure_parent_dir($parent_dir);
        if (is_wp_error($directories_created)) {
            return $directories_created;
        }
    }

    if (!is_writable($parent_dir)) {
        /* translators: %s: directory path */
        return new WP_Error('directory_not_writable', sprintf(__('Parent directory is not writable: %s', 'nibwp'), $parent_dir));
    }

    return [
        'path' => $resolved,
        'max_bytes' => max(1, (int) $payload['max_bytes']),
        'overwrite' => $payload['overwrite'] === true,
        'directories_created' => $directories_created,
    ];
}

/**
 * Path of a resolved file relative to the uploads base directory, or null when
 * the file is not inside it.
 *
 * Both the real and the configured spelling of the base are tried. nibwp_resolve_path()
 * realpath()s the destination's folder only once that folder exists, so with a
 * symlinked uploads directory an existing month folder reads as the link target
 * while one about to be created still reads through the link.
 */
function nibwp_upload_relative_path(string $resolved): ?string
{
    // $create_dir false: asking where uploads live must not create this
    // month's folder as a side effect.
    $uploads = wp_upload_dir(null, false);
    $basedir = is_array($uploads) && is_string($uploads['basedir'] ?? null) ? $uploads['basedir'] : '';
    if ($basedir === '') {
        return null;
    }

    $real_basedir = realpath($basedir);
    $path = nibwp_path_normalize($resolved);
    foreach (array_unique([$real_basedir === false ? $basedir : $real_basedir, $basedir]) as $base) {
        $base = rtrim(nibwp_path_normalize($base), '/\\');
        // The file's folder must be the base or below it. Testing the folder
        // rather than the file keeps the base directory itself from counting.
        if ($base === '' || !nibwp_path_within(dirname($path), $base)) {
            continue;
        }

        return ltrim(str_replace('\\', '/', substr($path, strlen($base))), '/');
    }

    return null;
}

/**
 * Can this resolved path become a Media Library attachment owned by this user?
 *
 * Shared by link creation and the upload itself, so the two can never disagree
 * about what the library accepts.
 */
function nibwp_check_upload_attachment_target(string $resolved, int $user_id): bool|WP_Error
{
    // An attachment's URL is its path under the uploads base URL. A file
    // anywhere else would get a URL that serves nothing — or a different file.
    if (nibwp_upload_relative_path($resolved) === null) {
        $uploads = wp_upload_dir(null, false);

        return new WP_Error('attachment_outside_uploads', sprintf(
            /* translators: 1: uploads directory path, 2: the upload link option name */
            __('Only files inside the uploads directory (%1$s) can be registered in the Media Library. Choose a path inside it, or upload without %2$s.', 'nibwp'),
            is_array($uploads) && is_string($uploads['basedir'] ?? null) ? $uploads['basedir'] : '',
            'register_attachment',
        ), ['status' => 400]);
    }

    if ($user_id <= 0 || !user_can($user_id, 'upload_files')) {
        return new WP_Error(
            'attachment_upload_forbidden',
            sprintf(
                /* translators: %s: WordPress capability name */
                __('Registering a file in the Media Library requires the %s capability.', 'nibwp'),
                'upload_files',
            ),
            ['status' => 403],
        );
    }

    // The upload request itself carries no logged-in user, so the allow-list
    // must be the link creator's: get_allowed_mime_types() narrows per user
    // (whether HTML is allowed depends on unfiltered_html).
    $filetype = wp_check_filetype(basename($resolved), get_allowed_mime_types($user_id));
    if (empty($filetype['ext']) || empty($filetype['type'])) {
        return new WP_Error('attachment_type_not_allowed', sprintf(
            /* translators: %s: file name */
            __('This file type is not allowed in the Media Library: %s', 'nibwp'),
            basename($resolved),
        ), ['status' => 400]);
    }

    return true;
}

/**
 * Register an uploaded file as a Media Library attachment.
 *
 * @param array<string, mixed> $payload Verified upload token payload.
 * @return array{attachment_id: int, url: string}|WP_Error
 */
function nibwp_register_uploaded_attachment(string $path, array $payload, bool $created): array|WP_Error
{
    $relative = nibwp_upload_relative_path($path);
    if ($relative === null) {
        return new WP_Error('attachment_outside_uploads', __('The file is not inside the uploads directory.', 'nibwp'));
    }

    $user_id = (int) $payload['user_id'];
    $filetype = wp_check_filetype(basename($path), get_allowed_mime_types($user_id));
    if (empty($filetype['type'])) {
        return new WP_Error('attachment_type_not_allowed', __('This file type is not allowed in the Media Library.', 'nibwp'));
    }

    $uploads = wp_upload_dir(null, false);
    $guid = rtrim((string) $uploads['baseurl'], '/') . '/' . $relative;
    $title = (string) $payload['title'];

    // REST requests do not load the admin include that generates thumbnails.
    require_once ABSPATH . 'wp-admin/includes/image.php';

    // Overwriting a file that is already in the library must not add a second
    // row for the same bytes: deleting either row would delete the file out
    // from under the other. A freshly created file cannot have a row yet, so
    // the lookup is skipped then.
    $attachment_id = $created ? 0 : attachment_url_to_postid($guid);
    if ($attachment_id > 0) {
        if ($title !== '') {
            wp_update_post(['ID' => $attachment_id, 'post_title' => $title]);
        }
    } else {
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $filetype['type'],
            'post_title' => $title !== '' ? $title : sanitize_text_field(pathinfo($path, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
            // The upload request is anonymous; the attachment belongs to whoever minted the link.
            'post_author' => $user_id,
            'guid' => $guid,
        ], $path, 0, true);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        if (!is_int($attachment_id) || $attachment_id <= 0) {
            return new WP_Error('attachment_insert_failed', __('WordPress did not return an attachment ID.', 'nibwp'));
        }
    }

    wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $path));

    if ($payload['alt_text'] !== '') {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $payload['alt_text']);
    }

    $url = wp_get_attachment_url($attachment_id);

    return [
        'attachment_id' => $attachment_id,
        'url' => is_string($url) && $url !== '' ? $url : $guid,
    ];
}

/**
 * Return the upload token from query args or headers.
 */
function nibwp_get_upload_token_from_request(WP_REST_Request $request): string
{
    $query_params = $request->get_query_params();
    if (array_key_exists('token', $query_params) && is_string($query_params['token'])) {
        return rawurldecode($query_params['token']);
    }

    $header_token = $request->get_header('x-nibwp-upload-token');
    if (is_string($header_token)) {
        return $header_token;
    }

    return '';
}

/**
 * Open the uploaded file stream, either from multipart/form-data or the raw request body.
 *
 * @return array{stream: resource, source: string, filename: string}|WP_Error
 */
function nibwp_open_upload_source(WP_REST_Request $request): array|WP_Error
{
    $file = nibwp_get_multipart_upload_file($request);
    if ($file !== null) {
        return nibwp_open_multipart_upload_source($file);
    }

    $stream = fopen('php://input', mode: 'rb');
    if ($stream === false) {
        return new WP_Error('upload_read_failed', __('Could not read upload request body.', 'nibwp'));
    }

    return [
        'stream' => $stream,
        'source' => 'raw',
        'filename' => '',
    ];
}

/**
 * Return a multipart file entry from the request, if present.
 *
 * @return array<array-key, mixed>|null
 */
function nibwp_get_multipart_upload_file(WP_REST_Request $request): ?array
{
    /** @var array<string, array<array-key, mixed>> $files */
    $files = $request->get_file_params();
    foreach ($files as $field => $candidate) {
        if ($field === 'file' || count($files) === 1) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Open a multipart upload source stream.
 *
 * @param array<array-key, mixed> $file File entry from WP_REST_Request::get_file_params().
 * @return array{stream: resource, source: string, filename: string}|WP_Error
 */
function nibwp_open_multipart_upload_source(array $file): array|WP_Error
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        return new WP_Error('upload_failed', nibwp_upload_error_message($error));
    }

    $tmp_name = '';
    if (array_key_exists('tmp_name', $file) && is_string($file['tmp_name'])) {
        $tmp_name = $file['tmp_name'];
    }
    if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
        return new WP_Error('invalid_upload', __('The multipart upload did not contain a valid uploaded file.', 'nibwp'));
    }

    $stream = fopen($tmp_name, mode: 'rb');
    if ($stream === false) {
        return new WP_Error('upload_read_failed', __('Could not read uploaded file.', 'nibwp'));
    }

    $name = '';
    if (array_key_exists('name', $file) && is_string($file['name'])) {
        $name = $file['name'];
    }

    return [
        'stream' => $stream,
        'source' => 'multipart',
        'filename' => sanitize_file_name($name),
    ];
}

/**
 * Write an upload stream to a new destination path.
 *
 * @param resource $source
 * @return array{bytes_written: int, created: bool}|WP_Error
 */
function nibwp_create_upload_stream($source, string $resolved, int $max_bytes): array|WP_Error
{
    $target = fopen($resolved, mode: 'xb');
    if ($target === false) {
        if (file_exists($resolved)) {
            /* translators: %s: file path */
            return new WP_Error('file_exists', sprintf(__('Destination already exists: %s', 'nibwp'), $resolved));
        }
        /* translators: %s: file path */
        return new WP_Error('upload_write_failed', sprintf(__('Could not open destination for writing: %s', 'nibwp'), $resolved));
    }

    $bytes_written = nibwp_copy_limited_stream(source: $source, target: $target, max_bytes: $max_bytes);
    fclose($target);

    if (is_wp_error($bytes_written)) {
        unlink($resolved);
        return $bytes_written;
    }

    chmod(filename: $resolved, permissions: 0644);

    return [
        'bytes_written' => $bytes_written,
        'created' => true,
    ];
}

/**
 * Write an upload stream, replacing the destination path if it exists.
 *
 * @param resource $source
 * @return array{bytes_written: int, created: bool}|WP_Error
 */
function nibwp_overwrite_upload_stream($source, string $resolved, int $max_bytes): array|WP_Error
{
    $created = !file_exists($resolved);
    $temporary_path = tempnam(dirname($resolved), prefix: '.nibwp-upload-');
    if ($temporary_path === false) {
        return new WP_Error('upload_temp_failed', sprintf(
            /* translators: %s: directory path */
            __('Could not create temporary upload file in: %s', 'nibwp'),
            dirname($resolved),
        ));
    }

    $target = fopen($temporary_path, mode: 'wb');
    if ($target === false) {
        unlink($temporary_path);
        /* translators: %s: file path */
        return new WP_Error('upload_write_failed', sprintf(__('Could not open destination for writing: %s', 'nibwp'), $resolved));
    }

    $bytes_written = nibwp_copy_limited_stream(source: $source, target: $target, max_bytes: $max_bytes);
    fclose($target);

    if (is_wp_error($bytes_written)) {
        unlink($temporary_path);
        return $bytes_written;
    }

    if (!rename($temporary_path, $resolved)) {
        unlink($temporary_path);
        /* translators: %s: file path */
        return new WP_Error('upload_move_failed', sprintf(__('Could not move uploaded file into place: %s', 'nibwp'), $resolved));
    }

    chmod(filename: $resolved, permissions: 0644);

    return [
        'bytes_written' => $bytes_written,
        'created' => $created,
    ];
}

/**
 * Copy a stream while enforcing a byte limit.
 *
 * @param resource $source
 * @param resource $target
 * @return int|WP_Error
 */
function nibwp_copy_limited_stream($source, $target, int $max_bytes): int|WP_Error
{
    $bytes_written = 0;
    while (!feof($source)) {
        $chunk = fread($source, length: 1_048_576);
        if ($chunk === false) {
            return new WP_Error('upload_read_failed', __('Could not read upload stream.', 'nibwp'));
        }
        if ($chunk === '') {
            continue;
        }

        $bytes_written += strlen($chunk);
        if ($bytes_written > $max_bytes) {
            return new WP_Error('upload_too_large', sprintf(
                /* translators: %d: maximum upload size in bytes */
                __('Upload exceeds the signed URL limit of %d bytes.', 'nibwp'),
                $max_bytes,
            ));
        }

        if (fwrite($target, $chunk) === false) {
            return new WP_Error('upload_write_failed', __('Could not write upload stream.', 'nibwp'));
        }
    }

    return $bytes_written;
}

/**
 * Return a human-readable upload error message.
 */
function nibwp_upload_error_message(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => __('Uploaded file exceeds the configured PHP upload size limit.', 'nibwp'),
        UPLOAD_ERR_PARTIAL => __('The file was only partially uploaded.', 'nibwp'),
        UPLOAD_ERR_NO_FILE => __('No file was uploaded.', 'nibwp'),
        UPLOAD_ERR_NO_TMP_DIR => __('The server is missing a temporary upload directory.', 'nibwp'),
        UPLOAD_ERR_CANT_WRITE => __('The server could not write the uploaded file to disk.', 'nibwp'),
        UPLOAD_ERR_EXTENSION => __('A PHP extension stopped the file upload.', 'nibwp'),
        default => __('The upload failed.', 'nibwp'),
    };
}

/**
 * Return the signing secret for upload URLs.
 */
function nibwp_upload_token_secret(): string
{
    return wp_salt('auth') . '|' . wp_salt('secure_auth') . '|nibwp-upload-link';
}

/**
 * Encode bytes with base64url.
 */
function nibwp_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), from: '+/', to: '-_'), characters: '=');
}

/**
 * Decode base64url bytes.
 */
function nibwp_base64url_decode(string $value): string|false
{
    $padding = strlen($value) % 4;
    if ($padding !== 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return base64_decode(strtr($value, from: '-_', to: '+/'), strict: true);
}
