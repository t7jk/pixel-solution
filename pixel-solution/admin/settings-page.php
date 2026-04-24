<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_init', 'pixel_solution_register_settings' );
function pixel_solution_register_settings() {
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

function pixel_solution_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$pixel_id   = get_option( 'mcs_pixel_id', '' );
	$capi_token = get_option( 'mcs_capi_token', '' );
	$test_code  = get_option( 'mcs_test_event_code', '' );

	$status_pixel = $pixel_id   ? '<span style="color:green;">&#9679; Set</span>' : '<span style="color:red;">&#9679; Missing</span>';
	$status_token = $capi_token ? '<span style="color:green;">&#9679; Set</span>' : '<span style="color:red;">&#9679; Missing</span>';
	?>
	<div class="wrap">
		<h1>Pixel Solution <span style="font-size:14px;font-weight:normal;color:#666;">by Tomasz Kalinowski</span></h1>

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
							class="regular-text" placeholder="e.g. 1234567890123456" />
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
							class="regular-text" placeholder="TEST12345 (optional)" />
						<p class="description">Fill in only when testing via Meta Ads Manager → Events Testing.</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Save Settings' ); ?>
		</form>

		<hr style="margin-top:30px;" />
		<p style="color:#888;font-size:12px;">
			Pixel Solution <?php echo PIXEL_SOLUTION_VERSION; ?> &nbsp;|&nbsp;
			<a href="https://x.com/tomas3man" target="_blank">@tomas3man on X</a> &nbsp;|&nbsp;
			<a href="https://github.com/t7jk/pixel-solution" target="_blank">GitHub</a>
		</p>
	</div>
	<?php
}
