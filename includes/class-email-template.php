<?php
/**
 * Responsive HTML email template builder.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AICS_Email_Template {

    /**
     * Build the subject line.
     *
     * @param string $frequency Frequency label (Daily, Weekly, etc.).
     * @return string
     */
    public static function subject( $frequency = 'Weekly' ) {
        $site_name = get_bloginfo( 'name' );
        $date      = wp_date( 'M j, Y' );
        /* translators: 1: site name, 2: frequency (Daily, Weekly, etc.), 3: date */
        $subject   = sprintf( __( '[%1$s] %2$s Changelog Summary — %3$s', 'changelog-tracker' ), $site_name, ucfirst( $frequency ), $date );
        return $subject;
    }

    /**
     * Render full HTML email.
     *
     * @param array $summaries   [ { url, summary } , ... ]
     * @param array $error_urls  URLs that failed.
     * @param array $unchanged   URLs with no changes detected.
     * @return string HTML email body.
     */
    public static function render( $summaries = [], $error_urls = [], $unchanged = [] ) {
        $site_name = esc_html( get_bloginfo( 'name' ) );
        $date      = wp_date( 'F j, Y' );

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html__( 'Changelog Summary', 'changelog-tracker' ); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,Ubuntu,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f5f7;">
<tr><td align="center" style="padding:30px 15px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

<!-- Header -->
<?php
$header_html = '<tr><td style="background:linear-gradient(135deg,#4f46e5,#7c3aed);padding:30px 40px;text-align:center;">'
    . '<h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:600;">' . esc_html__( 'Changelog Summary', 'changelog-tracker' ) . '</h1>'
    . '<p style="margin:8px 0 0;color:rgba(255,255,255,0.85);font-size:14px;">' . esc_html( $site_name ) . ' &middot; ' . esc_html( $date ) . '</p>'
    . '</td></tr>';
echo wp_kses_post( $header_html );
?>

<!-- Body -->
<tr>
<td style="padding:30px 40px;">

<?php if ( ! empty( $summaries ) ) : ?>
    <?php foreach ( $summaries as $index => $item ) : ?>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:25px;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;">
        <tr>
            <td style="background-color:#f9fafb;padding:12px 20px;border-bottom:1px solid #e5e7eb;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td>
                        <?php if ( $item['changed'] ?? true ) : ?>
                        <span style="display:inline-block;background-color:#dcfce7;color:#166534;font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;text-transform:uppercase;"><?php echo esc_html__( 'Updated', 'changelog-tracker' ); ?></span>
                        <?php else : ?>
                        <span style="display:inline-block;background-color:#fef3c7;color:#92400e;font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;text-transform:uppercase;"><?php echo esc_html__( 'No Changes', 'changelog-tracker' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding-top:6px;">
                        <a href="<?php echo esc_url( $item['url'] ); ?>" style="color:#4f46e5;font-size:14px;text-decoration:none;word-break:break-all;"><?php echo esc_html( $item['url'] ); ?></a>
                    </td>
                </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td style="padding:20px;font-size:14px;line-height:1.6;color:#374151;">
                <?php echo wp_kses_post( $item['summary'] ); ?>
            </td>
        </tr>
    </table>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ( ! empty( $unchanged ) ) : ?>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:25px;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;">
        <tr>
            <td style="padding:15px 20px;background-color:#fffbeb;">
                <span style="display:inline-block;background-color:#fef3c7;color:#92400e;font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;text-transform:uppercase;margin-bottom:8px;"><?php echo esc_html__( 'No Changes', 'changelog-tracker' ); ?></span>
                <p style="margin:8px 0 0;font-size:13px;color:#92400e;"><?php echo esc_html__( 'The following changelogs have not been updated since the last check:', 'changelog-tracker' ); ?></p>
                <ul style="margin:8px 0 0;padding-left:20px;">
                <?php foreach ( $unchanged as $u ) : ?>
                    <li style="font-size:13px;color:#78716c;margin:4px 0;">
                        <a href="<?php echo esc_url( $u ); ?>" style="color:#92400e;text-decoration:none;"><?php echo esc_html( $u ); ?></a>
                    </li>
                <?php endforeach; ?>
                </ul>
            </td>
        </tr>
    </table>
<?php endif; ?>

<?php if ( ! empty( $error_urls ) ) : ?>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:25px;border:1px solid #fecaca;border-radius:6px;overflow:hidden;">
        <tr>
            <td style="padding:15px 20px;background-color:#fef2f2;">
                <span style="display:inline-block;background-color:#fee2e2;color:#991b1b;font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;text-transform:uppercase;margin-bottom:8px;"><?php echo esc_html__( 'Errors', 'changelog-tracker' ); ?></span>
                <p style="margin:8px 0 0;font-size:13px;color:#991b1b;"><?php echo esc_html__( 'Failed to process the following URLs:', 'changelog-tracker' ); ?></p>
                <ul style="margin:8px 0 0;padding-left:20px;">
                <?php foreach ( $error_urls as $e ) : ?>
                    <li style="font-size:13px;color:#b91c1c;margin:4px 0;"><?php echo esc_html( $e ); ?></li>
                <?php endforeach; ?>
                </ul>
            </td>
        </tr>
    </table>
<?php endif; ?>

<?php if ( empty( $summaries ) && empty( $unchanged ) && empty( $error_urls ) ) : ?>
    <p style="text-align:center;color:#6b7280;font-size:14px;padding:20px 0;"><?php echo esc_html__( 'No changelog data available.', 'changelog-tracker' ); ?></p>
<?php endif; ?>

</td>
</tr>

<!-- Footer -->
<?php
$footer_html = '<tr><td style="padding:20px 40px;background-color:#f9fafb;border-top:1px solid #e5e7eb;text-align:center;">'
    /* translators: %s: plugin name */
    . '<p style="margin:0;font-size:12px;color:#9ca3af;">' . sprintf( esc_html__( 'Powered by %s', 'changelog-tracker' ), '<strong>Changelog Tracker</strong>' ) . '</p>'
    . '<p style="margin:6px 0 0;font-size:11px;color:#d1d5db;">' . esc_html__( 'To stop receiving these emails, deactivate the plugin or change the notification email in settings.', 'changelog-tracker' ) . '</p>'
    . '</td></tr>';
echo wp_kses_post( $footer_html );
?>

</table>
</td></tr>
</table>
</body>
</html>
        <?php
        return ob_get_clean();
    }
}
