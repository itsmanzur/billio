/**
 * Billio Docs & Features Interactive JS.
 *
 * @package Billio
 */

(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		initTabs();
		initCategoryFilters();
		initSearch();
		initFaqAccordion();
		initSidebarScroll();
	});

	/**
	 * Tab Switching logic.
	 */
	function initTabs() {
		var tabButtons = document.querySelectorAll('.billio-docs-tab-btn');
		var tabPanels = document.querySelectorAll('.billio-docs-tab-panel');

		if (!tabButtons.length || !tabPanels.length) {
			return;
		}

		function activateTab(targetId) {
			tabButtons.forEach(function (btn) {
				var isTarget = btn.getAttribute('data-tab') === targetId;
				btn.classList.toggle('active', isTarget);
				btn.setAttribute('aria-selected', isTarget ? 'true' : 'false');
			});

			tabPanels.forEach(function (panel) {
				var isTarget = panel.id === targetId;
				panel.classList.toggle('active', isTarget);
			});

			if (history.replaceState) {
				history.replaceState(null, null, '#' + targetId);
			}
		}

		tabButtons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				var targetId = this.getAttribute('data-tab');
				activateTab(targetId);
			});
		});

		// Check hash on load
		var hash = window.location.hash.replace('#', '');
		if (hash && document.getElementById(hash)) {
			activateTab(hash);
		}
	}

	/**
	 * Feature Category Pills filtering.
	 */
	function initCategoryFilters() {
		var pills = document.querySelectorAll('.billio-pill-btn');
		var cards = document.querySelectorAll('.billio-feature-card');

		if (!pills.length || !cards.length) {
			return;
		}

		pills.forEach(function (pill) {
			pill.addEventListener('click', function () {
				pills.forEach(function (p) { p.classList.remove('active'); });
				this.classList.add('active');

				var category = this.getAttribute('data-category');

				cards.forEach(function (card) {
					if (category === 'all' || card.getAttribute('data-category') === category) {
						card.style.display = '';
					} else {
						card.style.display = 'none';
					}
				});
			});
		});
	}

	/**
	 * Live search for features and docs.
	 */
	function initSearch() {
		var searchInput = document.getElementById('billio-docs-search-input');
		if (!searchInput) {
			return;
		}

		searchInput.addEventListener('input', function () {
			var query = this.value.trim().toLowerCase();
			var activeTab = document.querySelector('.billio-docs-tab-panel.active');

			if (!activeTab) {
				return;
			}

			if (activeTab.id === 'tab-features') {
				var cards = activeTab.querySelectorAll('.billio-feature-card');
				cards.forEach(function (card) {
					var text = card.textContent.toLowerCase();
					if (!query || text.indexOf(query) !== -1) {
						card.style.display = '';
					} else {
						card.style.display = 'none';
					}
				});
			} else if (activeTab.id === 'tab-docs') {
				var guideCards = activeTab.querySelectorAll('.billio-guide-card');
				guideCards.forEach(function (card) {
					var text = card.textContent.toLowerCase();
					if (!query || text.indexOf(query) !== -1) {
						card.style.display = '';
					} else {
						card.style.display = 'none';
					}
				});

				var faqItems = activeTab.querySelectorAll('.billio-faq-item');
				faqItems.forEach(function (item) {
					var text = item.textContent.toLowerCase();
					if (!query || text.indexOf(query) !== -1) {
						item.style.display = '';
						if (query) {
							item.classList.add('active');
						}
					} else {
						item.style.display = 'none';
					}
				});
			}
		});
	}

	/**
	 * FAQ Accordions.
	 */
	function initFaqAccordion() {
		var triggers = document.querySelectorAll('.billio-faq-trigger');

		triggers.forEach(function (trigger) {
			trigger.addEventListener('click', function () {
				var parent = this.closest('.billio-faq-item');
				if (!parent) {
					return;
				}

				var isOpen = parent.classList.contains('active');
				parent.classList.toggle('active', !isOpen);
				this.setAttribute('aria-expanded', !isOpen ? 'true' : 'false');
			});
		});
	}

	/**
	 * Sidebar Smooth Scroll.
	 */
	function initSidebarScroll() {
		var links = document.querySelectorAll('.billio-docs-menu a');
		links.forEach(function (link) {
			link.addEventListener('click', function (e) {
				var targetId = this.getAttribute('href');
				if (targetId && targetId.indexOf('#') === 0) {
					var el = document.querySelector(targetId);
					if (el) {
						e.preventDefault();
						links.forEach(function (l) { l.classList.remove('active'); });
						this.classList.add('active');
						el.scrollIntoView({ behavior: 'smooth', block: 'start' });
					}
				}
			});
		});
	}

})();
