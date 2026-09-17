/**
 * Drag-and-drop ordering for coin taxonomy terms (Coins → Denomination/Material/… → drag a row).
 *
 * The order is stored per term as `coin_term_order` meta and becomes the default sort of every
 * term query, GraphQL included — see inc/Taxonomy/TermOrderService.php.
 */
(function ($) {
	'use strict';

	var config = window.coinsTermOrder || {};

	$(function () {
		var $list = $('#the-list');

		if (!$list.length || !config.taxonomy || !config.sortable) {
			return;
		}

		var $table = $list.closest('table');

		function termIds() {
			return $list
				.children('tr')
				.map(function () {
					return parseInt(String(this.id).replace('tag-', ''), 10);
				})
				.get()
				.filter(function (id) {
					return id > 0;
				});
		}

		/** Mirror the positions the server is about to write, so the column doesn't go stale. */
		function renumber() {
			$list.children('tr').each(function (index) {
				$(this)
					.find('.coins-term-order__value')
					.text((config.offset + index) * config.step);
			});
		}

		function notice(message, isError) {
			$('.coins-term-order__notice').remove();

			var $notice = $('<div/>', {
				'class':
					'notice is-dismissible coins-term-order__notice ' +
					(isError ? 'notice-error' : 'notice-success'),
				html: $('<p/>').text(message)
			});

			$('.wrap > h1').first().after($notice);

			if (!isError) {
				window.setTimeout(function () {
					$notice.fadeOut(400, function () {
						$notice.remove();
					});
				}, 2000);
			}
		}

		$list.sortable({
			items: '> tr',
			handle: '.coins-term-order__handle',
			axis: 'y',
			cursor: 'move',
			// A cloned row collapses to its content width once it leaves the table layout; freeze
			// the original cell widths so the dragged row still lines up with the ones below it.
			helper: function (event, $row) {
				var widths = $row
					.children()
					.map(function () {
						return $(this).outerWidth();
					})
					.get();

				var $helper = $row.clone();

				$helper.children().each(function (index) {
					$(this).width(widths[index]);
				});

				return $helper.addClass('coins-term-order__helper');
			},
			update: function () {
				renumber();
				$table.addClass('coins-term-order--saving');

				$.post(config.ajaxUrl, {
					action: config.action,
					nonce: config.nonce,
					taxonomy: config.taxonomy,
					offset: config.offset,
					ids: termIds()
				})
					.done(function (response) {
						if (response && response.success) {
							notice(config.i18n.saved, false);
						} else {
							notice(config.i18n.error, true);
						}
					})
					.fail(function () {
						notice(config.i18n.error, true);
					})
					.always(function () {
						$table.removeClass('coins-term-order--saving');
					});
			}
		});
	});
})(jQuery);
