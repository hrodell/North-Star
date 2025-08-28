<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * File: northstar-admin-ui.php
 * Version: 1.0.1
 * Admin page shell (menu + HTML skeleton) for the FullCalendar UI and modals.
 *
 * Fix in 1.0.1 (Task 4):
 * - Closed the header comment properly (previous paste had an unterminated block).
 *
 * Notes:
 * - Markup only; all logic lives in northstar-admin-ui.js.
 */

add_action('admin_menu', function(){
	if ( ! current_user_can('manage_woocommerce') ) return;

	add_menu_page(
		__('NorthStar Delivery Slots','northstar-delivery-slots-plugin'),
		__('NorthStar Delivery Slots','northstar-delivery-slots-plugin'),
		'manage_woocommerce',
		'northstar-slots',
		function(){
			?>
			<div class="wrap">
				<h1><?php esc_html_e('NorthStar Delivery Slots','northstar-delivery-slots-plugin'); ?></h1>
				<div id="nsds-calendar-controls" class="nsds-controls">
					<button class="button button-primary" id="nsds-generate"><?php esc_html_e('Generate Season','northstar-delivery-slots-plugin'); ?></button>
					<button class="button" id="nsds-add-new"><?php esc_html_e('Add New Timeslot','northstar-delivery-slots-plugin'); ?></button>
					<a class="button" id="nsds-export-slots" target="_blank"><?php esc_html_e('Export Slots CSV','northstar-delivery-slots-plugin'); ?></a>
					<a class="button" id="nsds-export-bookings" target="_blank"><?php esc_html_e('Export Bookings CSV','northstar-delivery-slots-plugin'); ?></a>
					<div class="nsds-flex-spacer"></div>
					<label class="nsds-lock-toggle">
						<input type="checkbox" id="nsds-season-lock">
						<span><?php esc_html_e('Season Lock','northstar-delivery-slots-plugin'); ?></span>
					</label>
					<span id="nsds-lock-hint" class="nsds-lock-hint">
						<?php esc_html_e('Relaxed: capacity & block/unblock allowed; Add/Duplicate/Delete/Edit date-window disabled.','northstar-delivery-slots-plugin'); ?>
					</span>
				</div>
				<div id="nsds-calendar"></div>
			</div>

			<!-- Edit Slot Modal -->
			<div id="nsds-modal" class="nsds-modal nsds-hidden" aria-hidden="true">
				<div class="nsds-modal__overlay"></div>
				<div class="nsds-modal__panel" role="dialog" aria-modal="true" aria-labelledby="nsds-modal-title">
					<div class="nsds-modal__header">
						<h2 id="nsds-modal-title"><?php esc_html_e('Edit Slot','northstar-delivery-slots-plugin'); ?></h2>
						<button class="button-link nsds-modal__close" id="nsds-close-modal" aria-label="Close">×</button>
					</div>
					<div class="nsds-modal__body">
						<form id="nsds-form">
							<input type="hidden" id="nsds-slot-id" value="">
							<div class="nsds-grid">
								<label><?php esc_html_e('Type','northstar-delivery-slots-plugin'); ?>
									<input type="text" id="nsds-type-readonly" readonly>
								</label>
								<label><?php esc_html_e('Date','northstar-delivery-slots-plugin'); ?>
									<input type="text" id="nsds-date-readonly" readonly>
								</label>
								<label><?php esc_html_e('Capacity','northstar-delivery-slots-plugin'); ?>
									<input type="number" min="0" id="nsds-capacity" value="25">
								</label>
								<label><?php esc_html_e('Start (24h)','northstar-delivery-slots-plugin'); ?>
									<div class="nsds-inline">
										<input type="time" id="nsds-start" step="900" value="">
										<button type="button" class="ns-inline-btn" id="nsds-start-enter">Enter</button>
									</div>
								</label>
								<label><?php esc_html_e('End (24h)','northstar-delivery-slots-plugin'); ?>
									<div class="nsds-inline">
										<input type="time" id="nsds-end" step="900" value="">
										<button type="button" class="ns-inline-btn" id="nsds-end-enter">Enter</button>
									</div>
								</label>
								<label><?php esc_html_e('Preview (12h)','northstar-delivery-slots-plugin'); ?>
									<input type="text" id="nsds-preview" readonly placeholder="—">
								</label>
							</div>
						</form>
						<hr/>
						<div class="nsds-actions-row">
							<button class="button button-primary" id="nsds-save"><?php esc_html_e('Save','northstar-delivery-slots-plugin'); ?></button>
							<button class="button" id="nsds-cancel"><?php esc_html_e('Cancel','northstar-delivery-slots-plugin'); ?></button>
							<button class="button button-link-delete" id="nsds-delete"><?php esc_html_e('Delete','northstar-delivery-slots-plugin'); ?></button>
						</div>
					</div>
				</div>
			</div>

			<!-- Duplicate Modal -->
			<div id="nsds-dup-modal" class="nsds-modal nsds-hidden" aria-hidden="true">
				<div class="nsds-modal__overlay"></div>
				<div class="nsds-modal__panel" role="dialog" aria-modal="true" aria-labelledby="nsds-dup-title">
					<div class="nsds-modal__header">
						<h2 id="nsds-dup-title"><?php esc_html_e('Duplicate Slot','northstar-delivery-slots-plugin'); ?></h2>
						<button class="button-link nsds-modal__close" id="nsds-close-dup" aria-label="Close">×</button>
					</div>
					<div class="nsds-modal__body">
						<p id="nsds-dup-source" class="description"></p>
						<div id="nsds-dup-calendar" style="margin:8px 0;"></div>
						<div class="nsds-grid">
							<label><?php esc_html_e('Time Window','northstar-delivery-slots-plugin'); ?>
								<select id="nsds-dup-window"></select>
							</label>
							<label><?php esc_html_e('Capacity','northstar-delivery-slots-plugin'); ?>
								<input type="number" min="0" id="nsds-dup-capacity" value="25">
							</label>
						</div>
						<div class="nsds-actions-row">
							<button class="button button-primary" id="nsds-dup-create"><?php esc_html_e('Create Slot','northstar-delivery-slots-plugin'); ?></button>
							<button class="button" id="nsds-dup-cancel"><?php esc_html_e('Cancel','northstar-delivery-slots-plugin'); ?></button>
						</div>
					</div>
				</div>
			</div>

			<!-- Add New Timeslot Modal -->
			<div id="nsds-add-modal" class="nsds-modal nsds-hidden" aria-hidden="true">
				<div class="nsds-modal__overlay"></div>
				<div class="nsds-modal__panel" role="dialog" aria-modal="true" aria-labelledby="nsds-add-title">
					<div class="nsds-modal__header">
						<h2 id="nsds-add-title"><?php esc_html_e('Add New Timeslot','northstar-delivery-slots-plugin'); ?></h2>
						<button class="button-link nsds-modal__close" id="nsds-close-add" aria-label="Close">×</button>
					</div>
					<div class="nsds-modal__body">
						<div class="nsds-grid">
							<label><?php esc_html_e('Type','northstar-delivery-slots-plugin'); ?>
								<select id="nsds-add-type">
									<option value="Delivery">Delivery</option>
									<option value="Removal">Removal</option>
								</select>
							</label>
							<label><?php esc_html_e('Capacity','northstar-delivery-slots-plugin'); ?>
								<input type="number" min="0" id="nsds-add-capacity" value="25">
							</label>
							<label><?php esc_html_e('Selected Date','northstar-delivery-slots-plugin'); ?>
								<input type="text" id="nsds-add-date" readonly placeholder="Click a date on the mini calendar">
							</label>
						</div>

						<p class="nsds-hint"><?php esc_html_e('Pick a date in the mini calendar, then enter start/end times below. Times use 24-hour format (e.g., 08:00 and 12:00). A 12-hour preview is shown automatically.','northstar-delivery-slots-plugin'); ?></p>

						<div id="nsds-add-calendar" style="margin:8px 0;"></div>

						<div class="nsds-grid">
							<label><?php esc_html_e('Start (24h)','northstar-delivery-slots-plugin'); ?>
								<div class="nsds-inline">
									<input type="time" id="nsds-add-start" step="900" value="">
									<button type="button" class="ns-inline-btn" id="nsds-add-start-enter">Enter</button>
								</div>
							</label>
							<label><?php esc_html_e('End (24h)','northstar-delivery-slots-plugin'); ?>
								<div class="nsds-inline">
									<input type="time" id="nsds-add-end" step="900" value="">
									<button type="button" class="ns-inline-btn" id="nsds-add-end-enter">Enter</button>
								</div>
							</label>
							<label><?php esc_html_e('Preview (12h)','northstar-delivery-slots-plugin'); ?>
								<input type="text" id="nsds-add-preview" readonly placeholder="—">
							</label>
						</div>

						<div class="nsds-actions-row">
							<button class="button button-primary" id="nsds-add-create"><?php esc_html_e('Create Timeslot','northstar-delivery-slots-plugin'); ?></button>
							<button class="button" id="nsds-add-cancel"><?php esc_html_e('Cancel','northstar-delivery-slots-plugin'); ?></button>
						</div>
					</div>
				</div>
			</div>

			<!-- Bookings Modal -->
			<div id="nsds-booked-modal" class="nsds-modal nsds-hidden" aria-hidden="true">
				<div class="nsds-modal__overlay"></div>
				<div class="nsds-modal__panel" role="dialog" aria-modal="true" aria-labelledby="nsds-booked-title">
					<div class="nsds-modal__header">
						<h2 id="nsds-booked-title"><?php esc_html_e('Bookings','northstar-delivery-slots-plugin'); ?></h2>
						<button class="button-link nsds-modal__close" id="nsds-booked-close" aria-label="Close">×</button>
					</div>
					<div class="nsds-modal__body">
						<div id="nsds-booked-table" class="nsds-bookings" aria-live="polite"></div>
						<div class="nsds-actions-row">
							<button class="button" id="nsds-booked-dismiss"><?php esc_html_e('Close','northstar-delivery-slots-plugin'); ?></button>
						</div>
					</div>
				</div>
			</div>
			<?php
		},
		'dashicons-calendar-alt',
		58
	);
});
