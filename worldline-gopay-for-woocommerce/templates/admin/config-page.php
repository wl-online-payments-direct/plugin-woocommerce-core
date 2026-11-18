<?php

namespace WOP;

/**
 * Host markup for the admin-ui SPA plus the bootstrap script that instantiates it.
 *
 * @var string $configJson Pre-encoded SPA configuration JSON (slashes already escaped).
 * @var string $migrationNotice Migration notice HTML ('' when there is none); shown above every tab.
 */
\defined('ABSPATH') || exit;
?>
<div id="op-page" class="op-page wc-settings-prevent-change-event">
    <main>
        <div class="opp-content-holder">
            <header id="op-main-header"></header>
            <?php 
if (!empty($migrationNotice)) {
    ?>
                <div id="op-migration-notice-holder"><?php 
    echo \wp_kses_post($migrationNotice);
    ?></div>
            <?php 
}
?>
            <main id="op-main-page-holder"></main>
            <div id="op-footer"></div>
        </div>
    </main>
    <div class="op-page-loader ops--hidden" id="op-spinner">
        <div class="op-loader opt--large">
            <span class="opp-spinner"></span>
        </div>
    </div>
</div>
<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        var cfg = <?php 
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from wp_json_encode() (slashes escaped), printed as a JS literal.
echo $configJson;
?>;

        window[cfg.brandCode] = window[cfg.brandCode] || {};
        var OnlinePaymentsFE = window[cfg.brandCode];

        OnlinePaymentsFE.translations = cfg.translations;
        OnlinePaymentsFE.baseImgUrl = cfg.baseImgUrl;
        OnlinePaymentsFE.brand = cfg.brand;

        OnlinePaymentsFE.utilities.showLoader();

        OnlinePaymentsFE.state = new OnlinePaymentsFE.StateController({
            brand: OnlinePaymentsFE.brand,
            storesUrl: cfg.stateConfig.storesUrl,
            connectionDetailsUrl: cfg.stateConfig.connectionDetailsUrl,
            currentStoreUrl: cfg.stateConfig.currentStoreUrl,
            stateUrl: cfg.stateConfig.stateUrl,
            versionUrl: cfg.stateConfig.versionUrl,
            pageConfiguration: cfg.pageConfiguration
        });

        OnlinePaymentsFE.state.setStoreId(cfg.storeId);
        OnlinePaymentsFE.state.display();
        OnlinePaymentsFE.utilities.hideLoader();
    });
</script>
<script type="text/javascript">
    /*
     * The payment-method side menu (.op-sliding-modal) and its backdrop (.op-dark-mask) are
     * position: fixed so they stay on screen while scrolling a long method list. Fixed elements
     * anchor to the viewport, so this bounds them to the visible extent of the plugin form
     * (#op-page) - which is inset from the screen - so they sit within the form, not the whole screen.
     */
    (function () {
        /*
         * The lowest edge of whatever is pinned to the top of the viewport (WP admin bar plus any
         * sticky/fixed WooCommerce settings chrome). Probe a vertical strip near the top and take the
         * greatest bottom of a fixed/sticky element that isn't part of our own drawer/mask (#op-page).
         * This is why hard-coding the admin-bar height clipped the panel header: sticky chrome sits
         * below it. Starting the panel just under this keeps the logo + method name fully visible.
         */
        function topChromeBottom() {
            var offset = 0;
            var midX = Math.round(window.innerWidth / 2);
            for (var y = 0; y < 200; y += 8) {
                var stack = document.elementsFromPoint(midX, y);
                for (var i = 0; i < stack.length; i++) {
                    var el = stack[i];
                    if (!el || (el.closest && el.closest('#op-page'))) {
                        continue;
                    }
                    var pos = window.getComputedStyle(el).position;
                    if (pos === 'fixed' || pos === 'sticky') {
                        var r = el.getBoundingClientRect();
                        if (r.top <= y + 1 && r.top < 200 && r.bottom > offset) {
                            offset = Math.round(r.bottom);
                        }
                    }
                }
            }
            return offset;
        }

        function alignToForm() {
            var opPage = document.getElementById('op-page');
            if (!opPage) {
                return;
            }
            var rect = opPage.getBoundingClientRect();
            var rightGap = Math.max(0, Math.round(window.innerWidth - rect.right));
            var leftEdge = Math.max(0, Math.round(rect.left));

            // Horizontally bound the drawer/mask to the form (rightGap/leftEdge). Vertically, start at
            // the form's top edge, but never above the pinned top chrome (admin bar + sticky settings
            // header) so the panel's own header (logo/name/close) is never clipped - then run down to
            // the bottom of the screen so scrolling never reveals an unlocked strip.
            var top = Math.max(topChromeBottom(), Math.round(rect.top));
            var height = Math.max(0, window.innerHeight - top);

            var panel = opPage.querySelector('.op-sliding-modal');
            if (panel) {
                // Dock the panel at the form's right edge (rightGap in from the screen edge), keeping
                // its designed width, so it slides in from the form - not the screen edge. Clear any
                // left/width overrides so they don't accumulate across calls.
                panel.style.left = '';
                panel.style.width = '';
                panel.style.right = rightGap + 'px';
                panel.style.top = top + 'px';
                panel.style.height = height + 'px';
            }
            var mask = opPage.querySelector('.op-dark-mask');
            if (mask) {
                // The core stylesheet gives the mask width: 100%. With left + width both set the
                // browser ignores right, so the mask overshoots the form's right edge and dims the
                // scrollbar strip. Force width: auto so left + right bound it to the form.
                mask.style.width = 'auto';
                mask.style.right = rightGap + 'px';
                mask.style.left = leftEdge + 'px';
                mask.style.top = top + 'px';
                mask.style.height = height + 'px';
            }
        }

        var scheduled = false;
        function scheduleAlign() {
            if (scheduled) {
                return;
            }
            scheduled = true;
            window.requestAnimationFrame(function () {
                scheduled = false;
                alignToForm();
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            var opPage = document.getElementById('op-page');
            if (opPage && window.MutationObserver) {
                new MutationObserver(scheduleAlign).observe(opPage, { childList: true, subtree: true });
            }
        });
        window.addEventListener('resize', scheduleAlign);
        window.addEventListener('scroll', scheduleAlign, true);
    })();
</script>
<?php 
