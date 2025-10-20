/* global require, Plugins, PluginHost, App, xhr, Notify, __ */

require(['dojo/_base/kernel', 'dojo/ready'], function (dojo, ready) {
	ready(function () {
		const config = JSON.parse('%%AUTO_TRANSLATE_CONFIG%%');

		Plugins.AutoTranslate = {
		config,
		state: new Map(), // article_id -> {status, translated, target, detected, title_translated}
		pending: new Set(),

		isReplaceMode() {
			return (this.config.display_mode || 'append') === 'replace';
		},

		isTitleReplaceMode() {
			if (!this.config.translate_titles)
				return false;

			return (this.config.title_display_mode || 'append') === 'replace';
		},

		isTitleReplaceMode() {
			if (!this.config.translate_titles)
				return false;

			return (this.config.title_display_mode || 'append') === 'replace';
		},

			captureTitleOriginals(articleId) {
				this.findTitleElements(articleId).forEach((title) => {
					if (!title.dataset.autoTranslateTitleOriginal) {
						title.dataset.autoTranslateTitleOriginal = title.textContent;
						title.dataset.autoTranslateTitleAttr = title.getAttribute('title') ?? '';
					}
				});
			},

			restoreTitles(articleId) {
				document.querySelectorAll(`[data-auto-translate-title-for="${articleId}"]`)
					.forEach((el) => el.remove());

				this.findTitleElements(articleId).forEach((title) => {
					if (title.dataset.autoTranslateTitleOriginal !== undefined) {
						title.textContent = title.dataset.autoTranslateTitleOriginal;
					}

				if (title.dataset.autoTranslateTitleAttr !== undefined) {
					const originalAttr = title.dataset.autoTranslateTitleAttr;

					if (originalAttr) {
						title.setAttribute('title', originalAttr);
					} else {
						title.removeAttribute('title');
					}
				}

				title.classList.remove('auto-translate-title-replaced');
				});
			},

			renderTitle(articleId, translatedTitle, target, detected) {
				this.restoreTitles(articleId);

				if (!this.config.translate_titles || !translatedTitle) {
					return;
				}

				this.captureTitleOriginals(articleId);

				const titles = this.findTitleElements(articleId);
				const targetUpper = target ? target.toUpperCase() : '';
				const detectedUpper = detected ? detected.toUpperCase() : '';
				const metaLabel = targetUpper ?
					(detectedUpper && detectedUpper !== targetUpper ? `${detectedUpper} → ${targetUpper}` : targetUpper) :
					'';

				titles.forEach((title) => {
					if (!title.dataset.autoTranslateTitleOriginal) {
						title.dataset.autoTranslateTitleOriginal = title.textContent;
					}

					if (!title.dataset.autoTranslateTitleAttr) {
						title.dataset.autoTranslateTitleAttr = title.getAttribute('title') ?? '';
					}

			if (this.isTitleReplaceMode()) {
				title.textContent = translatedTitle;

				const icon = document.createElement('span');
				icon.className = 'material-icons auto-translate-icon auto-translate-title-inline-icon';
				icon.textContent = 'translate';
				if (metaLabel) icon.title = metaLabel;
				title.prepend(icon);

				if (metaLabel) {
					title.setAttribute('title', metaLabel);
				} else {
					const originalAttr = title.dataset.autoTranslateTitleAttr;

					if (originalAttr) {
						title.setAttribute('title', originalAttr);
					} else {
						title.removeAttribute('title');
					}
				}

				title.classList.add('auto-translate-title-replaced');
					} else {
						const supplement = document.createElement('div');
						supplement.className = 'auto-translate-title-supplement';
						supplement.dataset.autoTranslateTitleFor = articleId;

						const icon = document.createElement('span');
						icon.className = 'material-icons auto-translate-icon';
						icon.textContent = 'translate';
						if (metaLabel) icon.title = metaLabel;
						supplement.appendChild(icon);

						const textSpan = document.createElement('span');
						textSpan.className = 'auto-translate-title-text';
						textSpan.textContent = translatedTitle;
						supplement.appendChild(textSpan);

						title.insertAdjacentElement('afterend', supplement);
					}
				});
			},

			ensureOriginal(container) {
				if (!this.isReplaceMode()) return;

				if (container && !container.dataset.autoTranslateOriginal) {
					container.dataset.autoTranslateOriginal = container.innerHTML;
				}
			},

			restoreOriginal(container) {
				if (!this.isReplaceMode()) return;

				if (container && container.dataset.autoTranslateOriginal) {
					container.innerHTML = container.dataset.autoTranslateOriginal;
				}

				if (container) {
					container.classList.remove('auto-translate-replaced');
				}
			},

			request(articleId, sourceIcon = null) {
				const containers = this.findContainers(articleId);

				if (!containers.length) {
					Notify.error(__('Unable to locate article content to translate.'));
					return;
				}

				this.fetch(articleId, containers, {manual: true, sourceIcon});
			},

			processRendered(node) {
				const articleId = this.getArticleId(node);

				if (!articleId)
					return;

				const containers = this.findContainers(articleId, node);

				if (!containers.length)
					return;

				this.captureTitleOriginals(articleId);

				const stored = this.state.get(articleId);

				if (stored && stored.status === 'ok') {
					containers.forEach((container) =>
						this.renderTranslation(container, stored.translated, stored.target, stored.detected || null));

					this.renderTitle(articleId, stored.title_translated, stored.target, stored.detected || null);
					return;
				}

				if (this.config.mode === 'auto_append' && !this.pending.has(articleId)) {
					this.fetch(articleId, containers, {manual: false});
				}
			},

			getArticleId(node) {
				if (!node) return null;

				if (node.getAttribute && node.getAttribute('data-article-id'))
					return parseInt(node.getAttribute('data-article-id'), 10);

				const articleNode = node.querySelector?.('.post[data-article-id]') ||
					node.querySelector?.('[data-article-id]');

				if (articleNode)
					return parseInt(articleNode.getAttribute('data-article-id'), 10);

				return null;
			},

			findContainers(articleId, context = null) {
				const containers = [];

				const pushContainer = (el) => {
					if (el && !containers.includes(el))
						containers.push(el);
				};

				if (context) {
					if (context.classList?.contains('cdm')) {
						pushContainer(context.querySelector('.content-inner'));
					} else {
						const postContent = context.querySelector?.(`.post[data-article-id="${articleId}"] .content`);
						pushContainer(postContent);
					}
				}

				if (!containers.length) {
					document.querySelectorAll(`.post[data-article-id="${articleId}"] .content`).forEach(pushContainer);
					document.querySelectorAll(`#RROW-${articleId} .content-inner`).forEach(pushContainer);
				}

				return containers;
			},

			findTitleElements(articleId) {
				const selectors = [
					`.post[data-article-id="${articleId}"] .header .title a.title`,
					`.post[data-article-id="${articleId}"] .header .title`,
					`#RROW-${articleId} .titleWrap .title`,
					`#RROW-${articleId} .title .title`,
				];

				const result = new Set();

				selectors.forEach((selector) => {
					document.querySelectorAll(selector).forEach((node) => {
						if (node)
							result.add(node);
					});
				});

				return Array.from(result);
			},

			fetch(articleId, containers, {manual, sourceIcon} = {manual: false, sourceIcon: null}) {
				if (this.pending.has(articleId))
					return;

				this.pending.add(articleId);

				this.captureTitleOriginals(articleId);
				this.restoreTitles(articleId);

				containers.forEach((container) => {
					this.ensureOriginal(container);
					this.restoreOriginal(container);
					container.querySelectorAll('[data-auto-translate-block]').forEach((el) => el.remove());
				});

				if (sourceIcon) {
					sourceIcon.classList.add('auto-translate-loading');
				}

				containers.forEach((container) => {
					this.removeMessage(container);
					this.showMessage(container, __('Translating article...'), 'progress');
				});

				xhr.json('backend.php', App.getPhArgs('auto_translate', 'translate', {id: articleId}), (reply) => {
					this.pending.delete(articleId);

					if (sourceIcon)
						sourceIcon.classList.remove('auto-translate-loading');

					if (!reply || typeof reply !== 'object') {
						this.handleError(articleId, containers, __('Unexpected response from translation service.'));
						return;
					}

					if (reply.status === 'ok') {
						this.state.set(articleId, {
							status: 'ok',
							translated: reply.translated,
							target: reply.target,
							detected: reply.detected || null,
							title_translated: reply.title_translated || null,
						});

						containers.forEach((container) => {
							this.removeMessage(container);
							this.renderTranslation(container, reply.translated, reply.target, reply.detected || null);
						});

						this.renderTitle(articleId, reply.title_translated || null, reply.target, reply.detected || null);
					} else if (reply.status === 'skipped') {
						this.state.set(articleId, {
							status: 'skipped',
							message: reply.message,
							target: reply.target,
							detected: reply.detected || null,
							title_translated: null,
						});

						containers.forEach((container) => {
							this.restoreOriginal(container);
							this.removeMessage(container);
							this.showMessage(container, reply.message, 'info');
						});

						this.renderTitle(articleId, null, reply.target, reply.detected || null);

						if (manual)
							Notify.info(reply.message);
					} else {
						const message = reply.message ? reply.message : __('Translation request failed.');
						this.handleError(articleId, containers, message, manual);
					}
				}, (error) => {
					console.warn('AutoTranslate failed', error);
					this.pending.delete(articleId);

					if (sourceIcon)
						sourceIcon.classList.remove('auto-translate-loading');

					this.handleError(articleId, containers, __('Translation request failed.'), manual);
				});
			},

			handleError(articleId, containers, message, manual = false) {
				this.state.delete(articleId);
				this.restoreTitles(articleId);

				containers.forEach((container) => {
					this.restoreOriginal(container);
					this.removeMessage(container);
					this.showMessage(container, message, 'error');
				});

				if (manual)
					Notify.error(message);
			},

			renderTranslation(container, html, target = '', detected = null) {
				if (!container) return;

				this.removeMessage(container);

				container.querySelectorAll('[data-auto-translate-block]').forEach((el) => el.remove());

				if (this.isReplaceMode()) {
					this.ensureOriginal(container);
					container.classList.add('auto-translate-replaced');

					const preservedAttachments = Array.from(container.children)
						.filter((node) => {
							if (!node || node.nodeType !== Node.ELEMENT_NODE)
								return false;

							const cls = node.classList;

							if (!cls)
								return false;

							return cls.contains('attachments-inline') ||
								cls.contains('attachments') ||
								cls.contains('enclosures') ||
								cls.contains('enclosure');
						});

					const wrapper = document.createElement('div');
					wrapper.className = 'auto-translate-replaced-wrapper';

					const targetUpper = target ? target.toUpperCase() : '';
					const detectedUpper = detected ? detected.toUpperCase() : '';

					const badge = document.createElement('div');
					badge.className = 'auto-translate-badge';

					const badgeIcon = document.createElement('span');
					badgeIcon.className = 'material-icons auto-translate-icon';
					badgeIcon.textContent = 'translate';

					if (detectedUpper && detectedUpper !== targetUpper) {
						badgeIcon.title = `${detectedUpper} → ${targetUpper}`;
					} else if (targetUpper) {
						badgeIcon.title = targetUpper;
					} else {
						badgeIcon.title = __('Translated content');
					}

					badge.appendChild(badgeIcon);

					const body = document.createElement('div');
					body.className = 'auto-translate-body auto-translate-body-inline';
					body.innerHTML = html;

					wrapper.appendChild(badge);
					wrapper.appendChild(body);

					container.innerHTML = '';
					container.appendChild(wrapper);

					preservedAttachments.forEach((node) => {
						container.appendChild(node);
					});

					return;
				}

				const details = document.createElement('details');
				details.className = 'auto-translate-block';
				details.dataset.autoTranslateBlock = '1';
				details.setAttribute('open', 'open');

				if (target)
					details.setAttribute('lang', target);

				const summary = document.createElement('summary');
				const targetUpper = target ? target.toUpperCase() : '';
				const detectedUpper = detected ? detected.toUpperCase() : '';

				const summaryIcon = document.createElement('span');
				summaryIcon.className = 'material-icons auto-translate-icon';
				summaryIcon.textContent = 'translate';

				if (detectedUpper && detectedUpper !== targetUpper) {
					summaryIcon.title = `${detectedUpper} → ${targetUpper}`;
				} else if (targetUpper) {
					summaryIcon.title = targetUpper;
				} else {
					summaryIcon.title = __('Translated content');
				}

				summary.appendChild(summaryIcon);

				const body = document.createElement('div');
				body.className = 'auto-translate-body';
				body.innerHTML = html;

				details.appendChild(summary);
				details.appendChild(body);

				container.appendChild(details);
			},

			showMessage(container, text, kind = 'info') {
				if (!container) return;

				this.removeMessage(container);

				const msg = document.createElement('div');
				msg.className = `auto-translate-message auto-translate-${kind}`;
				msg.dataset.autoTranslateMessage = kind;
				msg.innerText = text;

				container.appendChild(msg);
			},

			removeMessage(container) {
				container?.querySelectorAll('[data-auto-translate-message]').forEach((el) => el.remove());
			},
		};

		PluginHost.register(PluginHost.HOOK_ARTICLE_RENDERED, (node) => {
			Plugins.AutoTranslate.processRendered(node);
		});

		PluginHost.register(PluginHost.HOOK_ARTICLE_RENDERED_CDM, (row) => {
			Plugins.AutoTranslate.processRendered(row);
		});
	});
});
