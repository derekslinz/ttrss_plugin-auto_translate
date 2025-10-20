<?php

class Auto_Translate extends Plugin {

	private PluginHost $host;

	/**
	 * Default plugin configuration values.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults(): array {
		return [
			"translator_url" => "http://translate",
			"api_key" => "",
			"target_language" => "en",
			"mode" => "auto_append", // auto_append|manual
			"display_mode" => "append", // append|replace
			"translate_titles" => false,
			"title_display_mode" => "append", // append|replace
		];
	}

	public function about(): array {
		return [
			1.0,
			"Auto Translate",
			"Automatically translate article content using a LibreTranslate-compatible API.",
			"Derek Linz",
			true,
		];
	}

	public function init($host): void {
		$this->host = $host;

		$stored_url = $this->host->get($this, "translator_url", "");

		if ($stored_url === "http://translate:5000") {
			$this->host->set($this, "translator_url", $this->defaults()["translator_url"]);
		}

		$host->add_hook(PluginHost::HOOK_ARTICLE_BUTTON, $this);
		$host->add_hook(PluginHost::HOOK_PREFS_TAB, $this);
	}

	public function flags(): array {
		return [ "needs_curl" => true ];
	}

	public function get_js(): string {
		$config = array_merge($this->defaults(), $this->host->get_all($this));

		return str_replace(
			"%%AUTO_TRANSLATE_CONFIG%%",
			json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
			file_get_contents(__DIR__ . "/auto_translate.js")
		);
	}

	public function get_css(): string {
		return file_get_contents(__DIR__ . "/auto_translate.css");
	}

	public function hook_article_button($line): string {
		$id = (int)$line["id"];

		return "<i class='material-icons auto-translate-button' data-translate-article='"
			. $id
			. "' onclick=\"Plugins.AutoTranslate.request("
			. $id
			. ", this)\" title=\""
			. $this->__("Translate article")
			. "\">translate</i>";
	}

	public function hook_prefs_tab($tab): void {
		if ($tab !== "prefPrefs") {
			return;
		}

		$config = array_merge($this->defaults(), $this->host->get_all($this));
		?>
		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>translate</i> <?= $this->__("Article auto-translation") ?>">
			<form dojoType="dijit.form.Form">
				<?= \Controls\pluginhandler_tags($this, "save") ?>
				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('<?= $this->__("Saving data...") ?>', true);
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info(reply);
						});
					}
				</script>

				<section class="auto-translate-section">
					<header><?= $this->__("Translation service") ?></header>
					<fieldset>
						<label><?= $this->__("Service URL (LibreTranslate-compatible):") ?></label>
						<input dojoType="dijit.form.ValidationTextBox" required="true"
							style="width: 100%"
							name="translator_url"
							value="<?= htmlspecialchars($config["translator_url"]) ?>"/>
					</fieldset>
					<fieldset>
						<label><?= $this->__("API key (optional):") ?></label>
						<input dojoType="dijit.form.ValidationTextBox"
							style="width: 100%"
							name="api_key"
							value="<?= htmlspecialchars($config["api_key"]) ?>"/>
					</fieldset>
				</section>

				<section class="auto-translate-section">
					<header><?= $this->__("Translation behaviour") ?></header>
					<fieldset>
						<label><?= $this->__("Target language code (ISO-639-1, e.g. en, de):") ?></label>
						<input dojoType="dijit.form.ValidationTextBox" required="true"
							style="width: 15em"
							name="target_language"
							value="<?= htmlspecialchars($config["target_language"]) ?>"/>
					</fieldset>
					<fieldset>
						<label><?= $this->__("Mode:") ?></label>
						<select dojoType="dijit.form.Select" name="mode">
								<option value="auto_append" <?= $config["mode"] === "auto_append" ? "selected='selected'" : "" ?>>
									<?= $this->__("Translate automatically and append to or replace the original text") ?>
								</option>
								<option value="manual" <?= $config["mode"] === "manual" ? "selected='selected'" : "" ?>>
									<?= $this->__("Translate manually when requested") ?>
								</option>
							</select>
						</fieldset>
						<fieldset>
							<label><?= $this->__("Display translated content:") ?></label>
							<select dojoType="dijit.form.Select" name="display_mode">
								<option value="append" <?= $config["display_mode"] === "append" ? "selected='selected'" : "" ?>>
									<?= $this->__("Append translation below original content") ?>
								</option>
								<option value="replace" <?= $config["display_mode"] === "replace" ? "selected='selected'" : "" ?>>
									<?= $this->__("Replace original article content with translation") ?>
								</option>
							</select>
						</fieldset>
						<fieldset>
							<label for="auto-translate-titles">
								<input dojoType="dijit.form.CheckBox" type="checkbox"
									id="auto-translate-titles" name="translate_titles" value="1"
									<?= !empty($config["translate_titles"]) ? "checked='checked'" : "" ?>/>
								<?= $this->__("Translate article titles") ?>
							</label>
						</fieldset>
						<fieldset>
							<label><?= $this->__("Display translated titles:") ?></label>
							<select dojoType="dijit.form.Select" name="title_display_mode">
								<option value="append" <?= ($config["title_display_mode"] ?? $this->defaults()["title_display_mode"]) === "append" ? "selected='selected'" : "" ?>>
									<?= $this->__("Append translated title below original") ?>
								</option>
								<option value="replace" <?= ($config["title_display_mode"] ?? $this->defaults()["title_display_mode"]) === "replace" ? "selected='selected'" : "" ?>>
									<?= $this->__("Replace original title with translation") ?>
								</option>
							</select>
						</fieldset>
					</section>

				<hr/>

				<?= \Controls\submit_tag($this->__("Save")) ?>
			</form>
		</div>
		<?php
	}

	public function save(): void {
		$url = trim(clean($_POST["translator_url"] ?? ""));
		$url = $url !== "" ? rtrim($url, "/") : $this->defaults()["translator_url"];

		$api_key = trim($_POST["api_key"] ?? "");
		$target = strtolower(trim($_POST["target_language"] ?? ""));

		if ($target === "") {
			$target = $this->defaults()["target_language"];
		}

		$mode = $_POST["mode"] ?? $this->defaults()["mode"];
		if (!in_array($mode, ["auto_append", "manual"], true)) {
			$mode = $this->defaults()["mode"];
		}

		$display_mode = $_POST["display_mode"] ?? $this->defaults()["display_mode"];
		if (!in_array($display_mode, ["append", "replace"], true)) {
			$display_mode = $this->defaults()["display_mode"];
		}

		$translate_titles = filter_var($_POST["translate_titles"] ?? false, FILTER_VALIDATE_BOOLEAN);
		$title_display_mode = $_POST["title_display_mode"] ?? $this->defaults()["title_display_mode"];
		if (!in_array($title_display_mode, ["append", "replace"], true)) {
			$title_display_mode = $this->defaults()["title_display_mode"];
		}

		$this->host->set_array($this, [
			"translator_url" => $url,
			"api_key" => $api_key,
			"target_language" => $target,
			"mode" => $mode,
			"display_mode" => $display_mode,
			"translate_titles" => $translate_titles,
			"title_display_mode" => $title_display_mode,
		]);

		echo $this->__("Configuration saved.");
	}

	public function translate(): void {
		header("Content-Type: application/json; charset=utf-8");

		$article_id = (int)($_REQUEST["id"] ?? 0);

		if (!$article_id) {
			$this->print_error($this->__("Invalid article id."));
			return;
		}

		$owner_uid = $_SESSION["uid"] ?? null;

		if (!$owner_uid) {
			$this->print_error($this->__("Not authenticated."));
			return;
		}

		$sth = $this->pdo->prepare("SELECT ttrss_entries.content, ttrss_entries.lang, ttrss_entries.title,
				ttrss_user_entries.feed_id, ttrss_user_entries.owner_uid,
				(SELECT hide_images FROM ttrss_feeds WHERE id = ttrss_user_entries.feed_id) AS hide_images,
				(SELECT site_url FROM ttrss_feeds WHERE id = ttrss_user_entries.feed_id) AS site_url
			FROM ttrss_entries, ttrss_user_entries
			WHERE ttrss_entries.id = :id
				AND ttrss_user_entries.ref_id = ttrss_entries.id
				AND ttrss_user_entries.owner_uid = :uid");
		$sth->execute([":id" => $article_id, ":uid" => $owner_uid]);

		if (!($row = $sth->fetch())) {
			$this->print_error($this->__("Article not found."));
			return;
		}

		$mode = $this->host->get($this, "mode", $this->defaults()["mode"]);
		$target = $this->host->get($this, "target_language", $this->defaults()["target_language"]);
		$service_url = $this->host->get($this, "translator_url", $this->defaults()["translator_url"]);
		$api_key = $this->host->get($this, "api_key", $this->defaults()["api_key"]);
		$translate_titles = (bool)$this->host->get($this, "translate_titles", $this->defaults()["translate_titles"]);

		if (!$service_url) {
			$this->print_error($this->__("Translation service is not configured."));
			return;
		}

		$service_url = rtrim($service_url, "/");

		$tokens = @parse_url($service_url);

		if ($tokens && isset($tokens["port"]) && !in_array((int)$tokens["port"], [80, 443], true)) {
			$this->print_error($this->T_sprintf("Translation service must listen on port 80 or 443 (current configuration uses %d).", (int)$tokens["port"]));
			return;
		}

		$source_lang = $row["lang"] ?: "auto";

		if ($source_lang && $source_lang !== "auto" && strtolower($source_lang) === strtolower($target)) {
			print json_encode([
				"status" => "skipped",
				"message" => $this->__("Article is already in the target language."),
				"target" => $target,
			]);
			return;
		}

		$content = $row["content"];

		if (!$content) {
			$this->print_error($this->__("Empty article content."));
			return;
		}

		$img_placeholders = [];
		$content_for_translation = preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function (array $matches) use (&$img_placeholders): string {
				$index = count($img_placeholders);
				$img_placeholders[$index] = $matches[0];

				return '<span class="auto-translate-img-placeholder" data-auto-translate-img-placeholder="'
					. $index
					. '"></span>';
			},
			$content
		);

		if ($content_for_translation === null) {
			$content_for_translation = $content;
		}

		$params = [
			"q" => $content_for_translation,
			"source" => $source_lang ?: "auto",
			"target" => $target,
			"format" => "html",
		];

		if ($api_key !== "") {
			$params["api_key"] = $api_key;
		}

		$response = UrlHelper::fetch([
			"url" => $service_url . "/translate",
			"post_query" => $params,
			"timeout" => 30,
			"http_accept" => "application/json",
		]);

		if ($response === false) {
			$message = UrlHelper::$fetch_last_error ?: $this->__("Translation request failed.");

			$this->print_error($message, [
				"code" => UrlHelper::$fetch_last_error_code ?? 0,
				"content_type" => UrlHelper::$fetch_last_content_type ?? "",
				"response" => UrlHelper::$fetch_last_error_content ?? "",
			]);
			return;
		}

		$data = json_decode($response, true);

		if (!is_array($data) || !array_key_exists("translatedText", $data)) {
			$this->print_error($this->__("Unexpected response from translation service."), [
				"response" => $response,
			]);
			return;
		}

		$detected_lang = $data["detectedLanguage"] ?? null;

		if (is_array($detected_lang)) {
			$detected_lang = reset($detected_lang);
		}

		if (is_string($detected_lang)) {
			$detected_lang = strtolower($detected_lang);
		} else {
			$detected_lang = null;
		}

		if ($source_lang === "auto" && $detected_lang !== null) {
			// avoid translating when detected language already matches the requested target
			if ($detected_lang === strtolower($target)) {
				print json_encode([
					"status" => "skipped",
					"message" => $this->T_sprintf(
						__("Article already appears to be %s."),
						strtoupper($detected_lang)
					),
					"target" => $target,
					"detected" => $detected_lang,
					"title_translated" => null,
				]);
				return;
			}
		}

		$translated = (string)$data["translatedText"];
		$translated = preg_replace('/SC_(?:ON|OFF)/', '', $translated) ?? $translated;

		if ($img_placeholders) {
				$translated = preg_replace_callback(
					'/<span\b[^>]*data-auto-translate-img-placeholder=["\']?(\d+)["\']?[^>]*>(.*?)<\/span>/is',
					static function (array $matches) use ($img_placeholders): string {
						$idx = (int)$matches[1];

						return $img_placeholders[$idx] ?? $matches[0];
					},
					$translated
				) ?? $translated;
			}

		$title_translated = null;

		if ($translate_titles) {
			$title = trim((string)$row["title"]);

			if ($title !== "") {
				$title_source = $source_lang;

				if ($title_source === "auto" && $detected_lang !== null) {
					$title_source = $detected_lang;
				}

				$title_params = [
					"q" => $title,
					"source" => $title_source ?: "auto",
					"target" => $target,
					"format" => "text",
				];

				if ($api_key !== "") {
					$title_params["api_key"] = $api_key;
				}

				$title_response = UrlHelper::fetch([
					"url" => $service_url . "/translate",
					"post_query" => $title_params,
					"timeout" => 15,
					"http_accept" => "application/json",
				]);

				if ($title_response !== false) {
					$title_data = json_decode($title_response, true);

					if (is_array($title_data) && array_key_exists("translatedText", $title_data)) {
						if (is_string($title_data["translatedText"])) {
							$title_translated = trim(preg_replace('/SC_(?:ON|OFF)/', '', $title_data["translatedText"]) ?? $title_data["translatedText"]);
						}
					}
				}
			}
		}

		$translated = Sanitizer::sanitize(
			$translated,
			(bool)$row["hide_images"],
			$owner_uid,
			(string)($row["site_url"] ?? ""),
			null,
			$article_id
		);

		print json_encode([
			"status" => "ok",
			"article_id" => $article_id,
			"target" => $target,
			"mode" => $mode,
			"translated" => $translated,
			"detected" => $detected_lang,
			"title_translated" => $title_translated,
		]);
	}

	private function print_error(string $message, array $meta = []): void {
		print json_encode([
			"status" => "error",
			"message" => $message,
			...($meta ? ["meta" => $meta] : []),
		]);
	}

	public function api_version(): int {
		return 2;
	}
}
