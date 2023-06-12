<?php
/**
 * Reporting Sales Tab
 *
 * @package LifterLMS/Templates/Admin
 *
 * @since Unknown
 * @since 7.2.0 Add content tag param to widget options.
 * @version 7.2.0
 */

defined( 'ABSPATH' ) || exit;
if ( ! is_admin() ) {
	exit;
}

?>

<?php foreach ( $widget_data as $row => $widgets ) : ?>
	<div class="llms-widget-row llms-widget-row-<?php $row; ?>">
	<?php foreach ( $widgets as $id => $opts ) : ?>

		<div class="llms-widget-<?php echo $opts['cols']; ?>">
			<div class="llms-widget is-loading" data-method="<?php echo $id; ?>" id="llms-widget-<?php echo $id; ?>">

				<p class="llms-label"><?php echo $opts['title']; ?></p>

				<?php if ( ! empty( $opts['link'] ) ) { ?>
					<a href="<?php echo esc_url( $opts['link'] ); ?>">
				<?php } ?>

				<?php
				printf(
					'<%s class="llms-widget-content">%s</%s>',
					esc_html( $opts['content_tag'] ?? 'h3' ),
					esc_html( $opts['content'] ?? '' ),
					esc_html( $opts['content_tag'] ?? 'h3' )
				);
				?>

				<?php if ( ! empty( $opts['link'] ) ) { ?>
					</a>
				<?php } ?>

				<span class="spinner"></span>

				<i class="fa fa-info-circle llms-widget-info-toggle"></i>
				<div class="llms-widget-info">
					<p><?php echo $opts['info']; ?></p>
				</div>

			</div>
		</div>

	<?php endforeach; ?>
	</div>
<?php endforeach; ?>

<div class="llms-charts-wrapper" id="llms-charts-wrapper"></div>

<div id="llms-analytics-json" style="display:none;"><?php echo $json; ?></div>
