<?php
/**
 * Plugin Name: RCKFLR Generador de Imágenes Destacadas
 * Description: Genera imágenes destacadas para publicaciones utilizando la IA de Dalle 3 de OpenAI.
 * Version: 1.0
 * Author: Mauricio Perera
 * Author URI: https://www.linkedin.com/in/mauricioperera/
 * Donate link: https://www.buymeacoffee.com/rckflr
 * Text Domain: rckflr-generador-imagenes-destacadas
 */


// Abortar si este archivo se llama directamente.
if ( ! defined( 'WPINC' ) ) {
    die;
}

function rckflr_enqueue_scripts($hook) {
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
        return;
    }

    ?>
    <script type='text/javascript'>
    document.addEventListener('DOMContentLoaded', function() {
        const generateBtn = document.getElementById('rckflr_generate_btn');
        if (generateBtn) {
            generateBtn.addEventListener('click', function(e) {
                e.preventDefault();
                const postId = generateBtn.dataset.postid;

                generateBtn.textContent = 'Generando...';
                generateBtn.disabled = true;

                wp.apiRequest({
                    path: '/rckflr/v1/generate-image/' + postId,
                    method: 'POST'
                }).then(function(response) {
                    if(response.success) {
                        alert('¡Imagen destacada generada con éxito!');
                        location.reload();
                    } else {
                        alert('Error: ' + (response.data.message || 'Algo salió mal'));
                    }
                }).catch(function(error) {
                    alert('Fallo en la solicitud: ' + error.message);
                }).finally(function() {
                    generateBtn.textContent = 'Generar Imagen';
                    generateBtn.disabled = false;
                });
            });
        }
    });
    </script>
    <?php
}
add_action('admin_enqueue_scripts', 'rckflr_enqueue_scripts');

function rckflr_register_meta_box() {
    add_meta_box(
        'rckflr_featured_image_generator',
        __('Generar Imagen Destacada', 'rckflr-generador-imagenes-destacadas'),
        'rckflr_display_generator_button',
        null,
        'side',
        'high'
    );
}
add_action('add_meta_boxes', 'rckflr_register_meta_box');

function rckflr_display_generator_button($post) {
    echo '<button type="button" id="rckflr_generate_btn" data-postid="'. esc_attr($post->ID) .'" class="button button-primary button-large">'.__('Generar Imagen', 'rckflr-generador-imagenes-destacadas').'</button>';
}

function rckflr_register_rest_route() {
    register_rest_route('rckflr/v1', '/generate-image/(?P<id>\d+)', [
        'methods'             => 'POST',
        'callback'            => 'rckflr_handle_generate_image',
        'permission_callback' => 'rckflr_check_permissions',
        'args'                => [
            'id' => [
                'required'          => true,
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ],
        ],
    ]);
}
add_action('rest_api_init', 'rckflr_register_rest_route');

function rckflr_check_permissions(WP_REST_Request $request) {
    return current_user_can('edit_post', $request['id']);
}

function rckflr_handle_generate_image(WP_REST_Request $request) {
    $post_id = $request['id'];
    $post_data = get_post($post_id);
    $excerpt = !empty($post_data->post_excerpt) ? $post_data->post_excerpt : wp_trim_words($post_data->post_content, 100);
    $api_key = get_option('rckflr_openai_api_key', '');


    if ( empty($api_key) ) {
        return new WP_Error('missing_api_key', __('Falta la clave API de OpenAI.', 'rckflr-generador-imagenes-destacadas'));
    }

    $prompt_response = wp_remote_post(
        'https://api.openai.com/v1/chat/completions',
        [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => json_encode([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Basado en la entrada del usuario, debes generar una sola frase, un prompt detallado para generar una imagen usando la generación de imágenes IA. La imagen es la miniatura de la publicación del blog, y el contenido que el usuario pasa es parte de esa publicación del blog. Destila un solo concepto o tema basado en la entrada del usuario, luego crea el prompt para la generación de la imagen.'
                    ],
                    [
                        'role' => 'user',
                        'content' => sanitize_text_field($excerpt),
                    ],
                ],
            ]),
            'timeout' => 60,
        ]
    );

    if (is_wp_error($prompt_response) || wp_remote_retrieve_response_code($prompt_response) !== 200) {
        return new WP_Error('api_error', __('Error al comunicarse con la API de OpenAI.', 'rckflr-generador-imagenes-destacadas'));
    }

    $prompt_data = json_decode(wp_remote_retrieve_body($prompt_response), true);
    $prompt = $prompt_data['choices'][0]['message']['content'] ?? '';

    if (empty($prompt)) {
        return new WP_Error('prompt_error', __('No se pudo generar el prompt de la imagen.', 'rckflr-generador-imagenes-destacadas'));
    }

    $image_response = wp_remote_post(
        'https://api.openai.com/v1/images/generations',
        [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => json_encode([
                'model' => 'dall-e-3',
                'prompt' => sanitize_text_field($prompt),
                'n' => 1,
                'size' => '1792x1024',
            ]),
            'timeout' => 60,
        ]
    );

    if (is_wp_error($image_response) || wp_remote_retrieve_response_code($image_response) !== 200) {
        return new WP_Error('api_error', __('Error al generar la imagen con la API Dalle 3.', 'rckflr-generador-imagenes-destacadas'));
    }

    $image_data = json_decode(wp_remote_retrieve_body($image_response), true);
    $image_url = $image_data['data'][0]['url'] ?? '';

    if (empty($image_url)) {
        return new WP_Error('image_error', __('No se pudo obtener la URL de la imagen.', 'rckflr-generador-imagenes-destacadas'));
    }

    $image_id = rckflr_upload_image_to_media_library($image_url, $post_id);

    if (is_wp_error($image_id)) {
        return $image_id;
    }

    set_post_thumbnail($post_id, $image_id);

    return rest_ensure_response(['success' => true]);
}

function rckflr_upload_image_to_media_library($image_url, $post_id) {
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    add_filter('upload_mimes', 'rckflr_custom_upload_mimes');

    $tmp = rckflr_custom_download_image($image_url);

    if (is_wp_error($tmp)) {
        return $tmp;
    }

    $file_ext = pathinfo(parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION);
    $file_name = sanitize_file_name($post_id . '-' . time() . '.' . $file_ext);

    $file_array = [
        'name' => $file_name,
        'tmp_name' => $tmp,
    ];

    $id = media_handle_sideload($file_array, $post_id, __('Imagen destacada generada', 'rckflr-generador-imagenes-destacadas'));

    if (is_wp_error($id)) {
        @unlink($file_array['tmp_name']);
        return $id;
    }

    return $id;
}

function rckflr_custom_upload_mimes($mimes) {
    $mimes['png'] = 'image/png';
    return $mimes;
}

function rckflr_custom_download_image($image_url) {
    $ch = curl_init($image_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $data = curl_exec($ch);
    curl_close($ch);

    if ($data === false) {
        return new WP_Error('download_error', __('Error al descargar la imagen.', 'rckflr-generador-imagenes-destacadas'));
    }
$file_ext = pathinfo(parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION);
    $tmp_fname = tempnam(sys_get_temp_dir(), 'rckflr_') . '.' . $file_ext;
    file_put_contents($tmp_fname, $data);

    return $tmp_fname;
}

function rckflr_admin_menu() {
    add_options_page('Configuración del Generador de Imágenes Destacadas', 'Generador de Imágenes', 'manage_options', 'rckflr-settings', 'rckflr_settings_page');
}
add_action('admin_menu', 'rckflr_admin_menu');

function rckflr_settings_page() {
    ?>
    <div class="wrap">
    <h1>Configuración del Generador de Imágenes Destacadas</h1>
    <form method="post" action="options.php">
        <?php
        settings_fields('rckflr-settings');
        do_settings_sections('rckflr-settings');
        submit_button();
        ?>
    </form>
    </div>
    <?php
}

function rckflr_register_settings() {
    register_setting('rckflr-settings', 'rckflr_openai_api_key');
    add_settings_section('rckflr_api_settings', 'Configuración de API', null, 'rckflr-settings');
    add_settings_field('rckflr_openai_api_key_field', 'Clave API de OpenAI', 'rckflr_openai_api_key_field_callback', 'rckflr-settings', 'rckflr_api_settings');
}
add_action('admin_init', 'rckflr_register_settings');

function rckflr_openai_api_key_field_callback() {
    $api_key = get_option('rckflr_openai_api_key');
    echo '<input type="text" id="rckflr_openai_api_key" name="rckflr_openai_api_key" value="' . esc_attr($api_key) . '" />';
}
