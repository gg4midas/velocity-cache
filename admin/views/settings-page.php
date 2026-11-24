<?php
/**
 * 设置页模板。
 *
 * @package VelocityCache
 * @var array    $settings 当前设置。
 * @var array    $status   状态数据。
 * @var VELOCA_Admin $this     后台模块实例。
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap veloca-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<p class="description"><?php esc_html_e( 'Page cache, HTML/CSS/JS optimization and cache version management. All actions require manage_options and pass security checks.', 'velocity-cache' ); ?></p>

	<form method="post" action="options.php">
		<?php
		settings_errors( VELOCA_Settings::OPTION );
		settings_fields( VELOCA_Settings::GROUP );
		do_settings_sections( VELOCA_Settings::PAGE );
		submit_button( __( 'Save Settings', 'velocity-cache' ) );
		?>
	</form>

	<hr>

	<h2><?php esc_html_e( 'Cache Tools', 'velocity-cache' ); ?></h2>
	<div class="veloca-tools">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="veloca_purge_cache">
			<input type="hidden" name="scope" value="pages">
			<?php wp_nonce_field( 'veloca_purge_cache' ); ?>
			<button type="submit" class="button button-secondary veloca-confirm" data-confirm="<?php echo esc_attr__( 'Purge all page cache?', 'velocity-cache' ); ?>">
				<?php esc_html_e( 'Purge Page Cache', 'velocity-cache' ); ?>
			</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="veloca_purge_cache">
			<input type="hidden" name="scope" value="assets">
			<?php wp_nonce_field( 'veloca_purge_cache' ); ?>
			<button type="submit" class="button button-secondary veloca-confirm" data-confirm="<?php echo esc_attr__( 'Purge page cache and optimized assets? Files will be rebuilt on the next request.', 'velocity-cache' ); ?>">
				<?php esc_html_e( 'Purge All Cache', 'velocity-cache' ); ?>
			</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="veloca_bump_version">
			<?php wp_nonce_field( 'veloca_bump_version' ); ?>
			<button type="submit" class="button button-secondary veloca-confirm" data-confirm="<?php echo esc_attr__( 'Bumping the asset version makes browsers re-download CSS/JS. Continue?', 'velocity-cache' ); ?>">
				<?php esc_html_e( 'Bump Asset Version', 'velocity-cache' ); ?>
			</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="veloca_preload">
			<?php wp_nonce_field( 'veloca_preload' ); ?>
			<button type="submit" class="button button-secondary">
				<?php esc_html_e( 'Start Preload', 'velocity-cache' ); ?>
			</button>
		</form>
	</div>

	<h2><?php esc_html_e( 'Status', 'velocity-cache' ); ?></h2>
	<table class="widefat striped veloca-status">
		<tbody>
		<?php foreach ( $status as $veloca_label => $veloca_value ) : ?>
			<tr>
				<th scope="row"><?php echo esc_html( $veloca_label ); ?></th>
				<td><?php echo esc_html( $veloca_value ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
