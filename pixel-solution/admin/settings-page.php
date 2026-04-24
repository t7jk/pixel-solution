<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_init', 'mcs_pixel_register_settings' );
function mcs_pixel_register_settings() {
	register_setting( 'mcs_pixel_options', 'mcs_pixel_id', [
		'sanitize_callback' => 'sanitize_text_field',
	] );
	register_setting( 'mcs_pixel_options', 'mcs_capi_token', [
		'sanitize_callback' => 'sanitize_text_field',
	] );
	register_setting( 'mcs_pixel_options', 'mcs_test_event_code', [
		'sanitize_callback' => 'sanitize_text_field',
	] );
}

function mcs_pixel_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$pixel_id   = get_option( 'mcs_pixel_id', '' );
	$capi_token = get_option( 'mcs_capi_token', '' );
	$test_code  = get_option( 'mcs_test_event_code', '' );

	$status_pixel = $pixel_id   ? '<span style="color:green;">&#9679; Uzupełniony</span>' : '<span style="color:red;">&#9679; Brak</span>';
	$status_token = $capi_token ? '<span style="color:green;">&#9679; Uzupełniony</span>' : '<span style="color:red;">&#9679; Brak</span>';
	?>
	<div class="wrap">
		<h1>MCS Meta Pixel &amp; CAPI</h1>

		<table class="widefat" style="max-width:500px;margin-bottom:20px;">
			<tbody>
				<tr><th>Pixel ID</th><td><?php echo $status_pixel; ?></td></tr>
				<tr><th>Access Token CAPI</th><td><?php echo $status_token; ?></td></tr>
			</tbody>
		</table>

		<form method="post" action="options.php">
			<?php settings_fields( 'mcs_pixel_options' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><label for="mcs_pixel_id">Pixel ID</label></th>
					<td>
						<input type="text" id="mcs_pixel_id" name="mcs_pixel_id"
							value="<?php echo esc_attr( $pixel_id ); ?>"
							class="regular-text" placeholder="np. 1234567890123456" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mcs_capi_token">Access Token CAPI</label></th>
					<td>
						<input type="password" id="mcs_capi_token" name="mcs_capi_token"
							value="<?php echo esc_attr( $capi_token ); ?>"
							class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mcs_test_event_code">Test Event Code</label></th>
					<td>
						<input type="text" id="mcs_test_event_code" name="mcs_test_event_code"
							value="<?php echo esc_attr( $test_code ); ?>"
							class="regular-text" placeholder="TEST12345 (opcjonalnie)" />
						<p class="description">Wypełnij tylko podczas testów w Meta Ads Manager → Testowanie zdarzeń.</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Zapisz ustawienia' ); ?>
		</form>
	</div>
	<?php
}
