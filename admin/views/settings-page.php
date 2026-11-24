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
	<p class="description"><?php esc_html_e( '页面缓存、HTML/CSS/JS 优化与缓存版本管理。所有操作均需 manage_options 权限并通过安全校验。', 'velocity-cache' ); ?></p>

	<form method="post" action="options.php">
		<?php
		settings_errors( VELOCA_Settings::OPTION );
		settings_fields( VELOCA_Settings::GROUP );
		do_settings_sections( VELOCA_Settings::PAGE );
		submit_button( __( '保存设置', 'velocity-cache' ) );
		?>
	</form>

	<hr>

	<h2><?php esc_html_e( '缓存工具', 'velocity-cache' ); ?></h2>
	<div class="veloca-tools">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="veloca_purge_cache">
			<input type="hidden" name="scope" value="pages">
			<?php wp_nonce_field( 'veloca_purge_cache' ); ?>
			<button type="submit" class="button button-secondary veloca-confirm" data-confirm="<?php echo esc_attr__( '确认清理所有页面缓存？', 'velocity-cache' ); ?>">
				<?php esc_html_e( '清理页面缓存', 'velocity-cache' ); ?>
			</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="veloca_purge_cache">
			<input type="hidden" name="scope" value="assets">
			<?php wp_nonce_field( 'veloca_purge_cache' ); ?>
			<button type="submit" class="button button-secondary veloca-confirm" data-confirm="<?php echo esc_attr__( '清理页面缓存与优化资源？文件会在下次请求时重建。', 'velocity-cache' ); ?>">
				<?php esc_html_e( '清理全部缓存', 'velocity-cache' ); ?>
			</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="veloca_bump_version">
			<?php wp_nonce_field( 'veloca_bump_version' ); ?>
			<button type="submit" class="button button-secondary veloca-confirm" data-confirm="<?php echo esc_attr__( '递增资源版本号会让浏览器重新下载 CSS/JS，确认继续？', 'velocity-cache' ); ?>">
				<?php esc_html_e( '递增资源版本号', 'velocity-cache' ); ?>
			</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="veloca_preload">
			<?php wp_nonce_field( 'veloca_preload' ); ?>
			<button type="submit" class="button button-secondary">
				<?php esc_html_e( '开始预加载', 'velocity-cache' ); ?>
			</button>
		</form>
	</div>

	<h2><?php esc_html_e( '运行状态', 'velocity-cache' ); ?></h2>
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
