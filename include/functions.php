<?php
	date_default_timezone_set('UTC');
	if (defined('E_DEPRECATED')) {
		error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
	} else {
		error_reporting(E_ALL & ~E_NOTICE);
	}

	require_once 'config.php';

	if (DB_TYPE == "pgsql") {
		define('SUBSTRING_FOR_DATE', 'SUBSTRING_FOR_DATE');
	} else {
		define('SUBSTRING_FOR_DATE', 'SUBSTRING');
	}

	define('THEME_VERSION_REQUIRED', 1.1);

	/**
	 * Return available translations names.
	 *
	 * @access public
	 * @return array A array of available translations.
	 */
	function get_translations() {
		$tr = array(
					"auto"  => "Detect automatically",
					"ca_CA" => "Català",
					"en_US" => "English",
					"es_ES" => "Español",
					"de_DE" => "Deutsch",
					"fr_FR" => "Français",
					"hu_HU" => "Magyar (Hungarian)",
					"it_IT" => "Italiano",
					"ja_JP" => "日本語 (Japanese)",
					"nb_NO" => "Norwegian bokmål",
					"ru_RU" => "Русский",
					"pt_BR" => "Portuguese/Brazil",
					"zh_CN" => "Simplified Chinese");

		return $tr;
	}

	require_once "lib/accept-to-gettext.php";
	require_once "lib/gettext/gettext.inc";

	function startup_gettext() {

		# Get locale from Accept-Language header
		$lang = al2gt(array_keys(get_translations()), "text/html");

		if (defined('_TRANSLATION_OVERRIDE_DEFAULT')) {
			$lang = _TRANSLATION_OVERRIDE_DEFAULT;
		}

		if ($_COOKIE["ttrss_lang"] && $_COOKIE["ttrss_lang"] != "auto") {
			$lang = $_COOKIE["ttrss_lang"];
		}

		/* In login action of mobile version */
		if ($_POST["language"] && defined('MOBILE_VERSION')) {
			$lang = $_POST["language"];
			$_COOKIE["ttrss_lang"] = $lang;
		}

		if ($lang) {
			if (defined('LC_MESSAGES')) {
				_setlocale(LC_MESSAGES, $lang);
			} else if (defined('LC_ALL')) {
				_setlocale(LC_ALL, $lang);
			}

			if (defined('MOBILE_VERSION')) {
				_bindtextdomain("messages", "../locale");
			} else {
				_bindtextdomain("messages", "locale");
			}

			_textdomain("messages");
			_bind_textdomain_codeset("messages", "UTF-8");
		}
	}

	startup_gettext();

	if (defined('MEMCACHE_SERVER')) {
		$memcache = new Memcache;
		$memcache->connect(MEMCACHE_SERVER, 11211);
	}

	require_once 'db-prefs.php';
	require_once 'version.php';

	define('MAGPIE_OUTPUT_ENCODING', 'UTF-8');

	define('SELF_USER_AGENT', 'Tiny Tiny RSS/' . VERSION . ' (http://tt-rss.org/)');
	define('MAGPIE_USER_AGENT', SELF_USER_AGENT);

	ini_set('user_agent', SELF_USER_AGENT);

	require_once 'lib/pubsubhubbub/publisher.php';

	$purifier = false;

	$tz_offset = -1;
	$utc_tz = new DateTimeZone('UTC');
	$schema_version = false;

	/**
	 * Print a timestamped debug message.
	 *
	 * @param string $msg The debug message.
	 * @return void
	 */
	function _debug($msg) {
		$ts = strftime("%H:%M:%S", time());
		if (function_exists('posix_getpid')) {
			$ts = "$ts/" . posix_getpid();
		}
		print "[$ts] $msg\n";
	} // function _debug

	/**
	 * Purge a feed old posts.
	 *
	 * @param mixed $link A database connection.
	 * @param mixed $feed_id The id of the purged feed.
	 * @param mixed $purge_interval Olderness of purged posts.
	 * @param boolean $debug Set to True to enable the debug. False by default.
	 * @access public
	 * @return void
	 */
	function purge_feed($link, $feed_id, $purge_interval, $debug = false) {

		if (!$purge_interval) $purge_interval = feed_purge_interval($link, $feed_id);

		$rows = -1;

		$result = db_query($link,
			"SELECT owner_uid FROM ttrss_feeds WHERE id = '$feed_id'");

		$owner_uid = false;

		if (db_num_rows($result) == 1) {
			$owner_uid = db_fetch_result($result, 0, "owner_uid");
		}

		if ($purge_interval == -1 || !$purge_interval) {
			if ($owner_uid) {
				ccache_update($link, $feed_id, $owner_uid);
			}
			return;
		}

		if (!$owner_uid) return;

		if (FORCE_ARTICLE_PURGE == 0) {
			$purge_unread = get_pref($link, "PURGE_UNREAD_ARTICLES",
				$owner_uid, false);
		} else {
			$purge_unread = true;
			$purge_interval = FORCE_ARTICLE_PURGE;
		}

		if (!$purge_unread) $query_limit = " unread = false AND ";

		if (DB_TYPE == "pgsql") {
			$pg_version = get_pgsql_version($link);

			if (preg_match("/^7\./", $pg_version) || preg_match("/^8\.0/", $pg_version)) {

				$result = db_query($link, "DELETE FROM ttrss_user_entries WHERE
					ttrss_entries.id = ref_id AND
					marked = false AND
					feed_id = '$feed_id' AND
					$query_limit
					ttrss_entries.date_updated < NOW() - INTERVAL '$purge_interval days'");

			} else {

				$result = db_query($link, "DELETE FROM ttrss_user_entries
					USING ttrss_entries
					WHERE ttrss_entries.id = ref_id AND
					marked = false AND
					feed_id = '$feed_id' AND
					$query_limit
					ttrss_entries.date_updated < NOW() - INTERVAL '$purge_interval days'");
			}

			$rows = pg_affected_rows($result);

		} else {

/*			$result = db_query($link, "DELETE FROM ttrss_user_entries WHERE
				marked = false AND feed_id = '$feed_id' AND
				(SELECT date_updated FROM ttrss_entries WHERE
					id = ref_id) < DATE_SUB(NOW(), INTERVAL $purge_interval DAY)"); */

			$result = db_query($link, "DELETE FROM ttrss_user_entries
				USING ttrss_user_entries, ttrss_entries
				WHERE ttrss_entries.id = ref_id AND
				marked = false AND
				feed_id = '$feed_id' AND
				$query_limit
				ttrss_entries.date_updated < DATE_SUB(NOW(), INTERVAL $purge_interval DAY)");

			$rows = mysql_affected_rows($link);

		}

		ccache_update($link, $feed_id, $owner_uid);

		if ($debug) {
			_debug("Purged feed $feed_id ($purge_interval): deleted $rows articles");
		}
	} // function purge_feed

	function feed_purge_interval($link, $feed_id) {

		$result = db_query($link, "SELECT purge_interval, owner_uid FROM ttrss_feeds
			WHERE id = '$feed_id'");

		if (db_num_rows($result) == 1) {
			$purge_interval = db_fetch_result($result, 0, "purge_interval");
			$owner_uid = db_fetch_result($result, 0, "owner_uid");

			if ($purge_interval == 0) $purge_interval = get_pref($link,
				'PURGE_OLD_DAYS', $owner_uid);

			return $purge_interval;

		} else {
			return -1;
		}
	}

	function purge_orphans($link, $do_output = false) {

		// purge orphaned posts in main content table
		$result = db_query($link, "DELETE FROM ttrss_entries WHERE
			(SELECT COUNT(int_id) FROM ttrss_user_entries WHERE ref_id = id) = 0");

		if ($do_output) {
			$rows = db_affected_rows($link, $result);
			_debug("Purged $rows orphaned posts.");
		}
	}

	function get_feed_update_interval($link, $feed_id) {
		$result = db_query($link, "SELECT owner_uid, update_interval FROM
			ttrss_feeds WHERE id = '$feed_id'");

		if (db_num_rows($result) == 1) {
			$update_interval = db_fetch_result($result, 0, "update_interval");
			$owner_uid = db_fetch_result($result, 0, "owner_uid");

			if ($update_interval != 0) {
				return $update_interval;
			} else {
				return get_pref($link, 'DEFAULT_UPDATE_INTERVAL', $owner_uid, false);
			}

		} else {
			return -1;
		}
	}

	function fetch_file_contents($url, $type = false, $login = false, $pass = false, $post_query = false) {
		$login = urlencode($login);
		$pass = urlencode($pass);

		if (function_exists('curl_init') && !ini_get("open_basedir")) {
			$ch = curl_init($url);

			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
			curl_setopt($ch, CURLOPT_TIMEOUT, 45);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_MAXREDIRS, 20);
			curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
			curl_setopt($ch, CURLOPT_USERAGENT, SELF_USER_AGENT);
			curl_setopt($ch, CURLOPT_ENCODING , "gzip");

			if ($post_query) {
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $post_query);
			}

			if ($login && $pass)
				curl_setopt($ch, CURLOPT_USERPWD, "$login:$pass");

			$contents = @curl_exec($ch);

			if ($contents === false) {
				curl_close($ch);
				return false;
			}

			$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
			curl_close($ch);

			if ($http_code != 200 || $type && strpos($content_type, "$type") === false) {
				return false;
			}

			return $contents;
		} else {
			if ($login && $pass ){
				$url_parts = array();

				preg_match("/(^[^:]*):\/\/(.*)/", $url, $url_parts);

				if ($url_parts[1] && $url_parts[2]) {
					$url = $url_parts[1] . "://$login:$pass@" . $url_parts[2];
				}
			}

			return @file_get_contents($url);
		}

	}

	/**
	 * Try to determine the favicon URL for a feed.
	 * adapted from wordpress favicon plugin by Jeff Minard (http://thecodepro.com/)
	 * http://dev.wp-plugins.org/file/favatars/trunk/favatars.php
	 *
	 * @param string $url A feed or page URL
	 * @access public
	 * @return mixed The favicon URL, or false if none was found.
	 */
	function get_favicon_url($url) {

		$favicon_url = false;

		if ($html = @fetch_file_contents($url)) {

			libxml_use_internal_errors(true);

			$doc = new DOMDocument();
			$doc->loadHTML($html);
			$xpath = new DOMXPath($doc);

			$base = $xpath->query('/html/head/base');
			foreach ($base as $b) {
				$url = $b->getAttribute("href");
				break;
			}

			$entries = $xpath->query('/html/head/link[@rel="shortcut icon" or @rel="icon"]');
			if (count($entries) > 0) {
				foreach ($entries as $entry) {
					$favicon_url = rewrite_relative_url($url, $entry->getAttribute("href"));
					break;
				}
			}
		}

		if (!$favicon_url)
			$favicon_url = rewrite_relative_url($url, "/favicon.ico");

		return $favicon_url;
	} // function get_favicon_url

	function check_feed_favicon($site_url, $feed, $link) {
#		print "FAVICON [$site_url]: $favicon_url\n";

		$icon_file = ICONS_DIR . "/$feed.ico";

		if (!file_exists($icon_file)) {
			$favicon_url = get_favicon_url($site_url);

			if ($favicon_url) {
				// Limiting to "image" type misses those served with text/plain
				$contents = fetch_file_contents($favicon_url); // , "image");

				if ($contents) {
					// Crude image type matching.
					// Patterns gleaned from the file(1) source code.
					if (preg_match('/^\x00\x00\x01\x00/', $contents)) {
						// 0       string  \000\000\001\000        MS Windows icon resource
						//error_log("check_feed_favicon: favicon_url=$favicon_url isa MS Windows icon resource");
					}
					elseif (preg_match('/^GIF8/', $contents)) {
						// 0       string          GIF8            GIF image data
						//error_log("check_feed_favicon: favicon_url=$favicon_url isa GIF image");
					}
					elseif (preg_match('/^\x89PNG\x0d\x0a\x1a\x0a/', $contents)) {
						// 0       string          \x89PNG\x0d\x0a\x1a\x0a         PNG image data
						//error_log("check_feed_favicon: favicon_url=$favicon_url isa PNG image");
					}
					elseif (preg_match('/^\xff\xd8/', $contents)) {
						// 0       beshort         0xffd8          JPEG image data
						//error_log("check_feed_favicon: favicon_url=$favicon_url isa JPG image");
					}
					else {
						//error_log("check_feed_favicon: favicon_url=$favicon_url isa UNKNOWN type");
						$contents = "";
					}
				}

				if ($contents) {
					$fp = @fopen($icon_file, "w");

					if ($fp) {
						fwrite($fp, $contents);
						fclose($fp);
						chmod($icon_file, 0644);
					}
				}
			}
		}
	}

	function print_select($id, $default, $values, $attributes = "") {
		print "<select name=\"$id\" id=\"$id\" $attributes>";
		foreach ($values as $v) {
			if ($v == $default)
				$sel = "selected=\"1\"";
			 else
			 	$sel = "";

			print "<option value=\"$v\" $sel>$v</option>";
		}
		print "</select>";
	}

	function print_select_hash($id, $default, $values, $attributes = "") {
		print "<select name=\"$id\" id='$id' $attributes>";
		foreach (array_keys($values) as $v) {
			if ($v == $default)
				$sel = 'selected="selected"';
			 else
			 	$sel = "";

			print "<option $sel value=\"$v\">".$values[$v]."</option>";
		}

		print "</select>";
	}

	function get_article_filters($filters, $title, $content, $link, $timestamp, $author, $tags) {
		$matches = array();

		if ($filters["title"]) {
			foreach ($filters["title"] as $filter) {
				$reg_exp = $filter["reg_exp"];
				$inverse = $filter["inverse"];
				if ((!$inverse && @preg_match("/$reg_exp/i", $title)) ||
						($inverse && !@preg_match("/$reg_exp/i", $title))) {

					array_push($matches, array($filter["action"], $filter["action_param"]));
				}
			}
		}

		if ($filters["content"]) {
			foreach ($filters["content"] as $filter) {
				$reg_exp = $filter["reg_exp"];
				$inverse = $filter["inverse"];

				if ((!$inverse && @preg_match("/$reg_exp/i", $content)) ||
						($inverse && !@preg_match("/$reg_exp/i", $content))) {

					array_push($matches, array($filter["action"], $filter["action_param"]));
				}
			}
		}

		if ($filters["both"]) {
			foreach ($filters["both"] as $filter) {
				$reg_exp = $filter["reg_exp"];
				$inverse = $filter["inverse"];

				if ($inverse) {
					if (!@preg_match("/$reg_exp/i", $title) && !preg_match("/$reg_exp/i", $content)) {
						array_push($matches, array($filter["action"], $filter["action_param"]));
					}
				} else {
					if (@preg_match("/$reg_exp/i", $title) || preg_match("/$reg_exp/i", $content)) {
						array_push($matches, array($filter["action"], $filter["action_param"]));
					}
				}
			}
		}

		if ($filters["link"]) {
			$reg_exp = $filter["reg_exp"];
			foreach ($filters["link"] as $filter) {
				$reg_exp = $filter["reg_exp"];
				$inverse = $filter["inverse"];

				if ((!$inverse && @preg_match("/$reg_exp/i", $link)) ||
						($inverse && !@preg_match("/$reg_exp/i", $link))) {

					array_push($matches, array($filter["action"], $filter["action_param"]));
				}
			}
		}

		if ($filters["date"]) {
			$reg_exp = $filter["reg_exp"];
			foreach ($filters["date"] as $filter) {
				$date_modifier = $filter["filter_param"];
				$inverse = $filter["inverse"];
				$check_timestamp = strtotime($filter["reg_exp"]);

				# no-op when timestamp doesn't parse to prevent misfires

				if ($check_timestamp) {
					$match_ok = false;

					if ($date_modifier == "before" && $timestamp < $check_timestamp ||
						$date_modifier == "after" && $timestamp > $check_timestamp) {
							$match_ok = true;
					}

					if ($inverse) $match_ok = !$match_ok;

					if ($match_ok) {
						array_push($matches, array($filter["action"], $filter["action_param"]));
					}
				}
			}
		}

		if ($filters["author"]) {
			foreach ($filters["author"] as $filter) {
				$reg_exp = $filter["reg_exp"];
				$inverse = $filter["inverse"];
				if ((!$inverse && @preg_match("/$reg_exp/i", $author)) ||
						($inverse && !@preg_match("/$reg_exp/i", $author))) {

					array_push($matches, array($filter["action"], $filter["action_param"]));
				}
			}
		}

		if ($filters["tag"]) {

			$tag_string = join(",", $tags);

			foreach ($filters["tag"] as $filter) {
				$reg_exp = $filter["reg_exp"];
				$inverse = $filter["inverse"];

				if ((!$inverse && @preg_match("/$reg_exp/i", $tag_string)) ||
						($inverse && !@preg_match("/$reg_exp/i", $tag_string))) {

					array_push($matches, array($filter["action"], $filter["action_param"]));
				}
			}
		}


		return $matches;
	}

	function find_article_filter($filters, $filter_name) {
		foreach ($filters as $f) {
			if ($f[0] == $filter_name) {
				return $f;
			};
		}
		return false;
	}

	function calculate_article_score($filters) {
		$score = 0;

		foreach ($filters as $f) {
			if ($f[0] == "score") {
				$score += $f[1];
			};
		}
		return $score;
	}

	function assign_article_to_labels($link, $id, $filters, $owner_uid) {
		foreach ($filters as $f) {
			if ($f[0] == "label") {
				label_add_article($link, $id, $f[1], $owner_uid);
			};
		}
	}

	function getmicrotime() {
		list($usec, $sec) = explode(" ",microtime());
		return ((float)$usec + (float)$sec);
	}

	function print_radio($id, $default, $true_is, $values, $attributes = "") {
		foreach ($values as $v) {

			if ($v == $default)
				$sel = "checked";
			 else
			 	$sel = "";

			if ($v == $true_is) {
				$sel .= " value=\"1\"";
			} else {
				$sel .= " value=\"0\"";
			}

			print "<input class=\"noborder\" dojoType=\"dijit.form.RadioButton\"
				type=\"radio\" $sel $attributes name=\"$id\">&nbsp;$v&nbsp;";

		}
	}

	function initialize_user_prefs($link, $uid, $profile = false) {

		$uid = db_escape_string($uid);

		if (!$profile) {
			$profile = "NULL";
			$profile_qpart = "AND profile IS NULL";
		} else {
			$profile_qpart = "AND profile = '$profile'";
		}

		if (get_schema_version($link) < 63) $profile_qpart = "";

		db_query($link, "BEGIN");

		$result = db_query($link, "SELECT pref_name,def_value FROM ttrss_prefs");

		$u_result = db_query($link, "SELECT pref_name
			FROM ttrss_user_prefs WHERE owner_uid = '$uid' $profile_qpart");

		$active_prefs = array();

		while ($line = db_fetch_assoc($u_result)) {
			array_push($active_prefs, $line["pref_name"]);
		}

		while ($line = db_fetch_assoc($result)) {
			if (array_search($line["pref_name"], $active_prefs) === FALSE) {
//				print "adding " . $line["pref_name"] . "<br>";

				if (get_schema_version($link) < 63) {
					db_query($link, "INSERT INTO ttrss_user_prefs
						(owner_uid,pref_name,value) VALUES
						('$uid', '".$line["pref_name"]."','".$line["def_value"]."')");

				} else {
					db_query($link, "INSERT INTO ttrss_user_prefs
						(owner_uid,pref_name,value, profile) VALUES
						('$uid', '".$line["pref_name"]."','".$line["def_value"]."', $profile)");
				}

			}
		}

		db_query($link, "COMMIT");

	}

	function get_ssl_certificate_id() {
		if ($_SERVER["REDIRECT_SSL_CLIENT_M_SERIAL"]) {
			return sha1($_SERVER["REDIRECT_SSL_CLIENT_M_SERIAL"] .
				$_SERVER["REDIRECT_SSL_CLIENT_V_START"] .
				$_SERVER["REDIRECT_SSL_CLIENT_V_END"] .
				$_SERVER["REDIRECT_SSL_CLIENT_S_DN"]);
		}
		return "";
	}

	function get_login_by_ssl_certificate($link) {

		$cert_serial = db_escape_string(get_ssl_certificate_id());

		if ($cert_serial) {
			$result = db_query($link, "SELECT login FROM ttrss_user_prefs, ttrss_users
				WHERE pref_name = 'SSL_CERT_SERIAL' AND value = '$cert_serial' AND
				owner_uid = ttrss_users.id");

			if (db_num_rows($result) != 0) {
				return db_escape_string(db_fetch_result($result, 0, "login"));
			}
		}

		return "";
	}

	function get_remote_user($link) {

		if (defined('ALLOW_REMOTE_USER_AUTH') && ALLOW_REMOTE_USER_AUTH) {
			return db_escape_string($_SERVER["REMOTE_USER"]);
		}

		return db_escape_string(get_login_by_ssl_certificate($link));
	}

	function get_remote_fakepass($link) {
		if (get_remote_user($link))
			return "******";
		else
			return "";
	}

	function authenticate_user($link, $login, $password, $force_auth = false) {

		if (!SINGLE_USER_MODE) {

			$pwd_hash1 = encrypt_password($password);
			$pwd_hash2 = encrypt_password($password, $login);
			$login = db_escape_string($login);

			$remote_user = get_remote_user($link);

			if ($remote_user && $remote_user == $login && $login != "admin") {

				$login = $remote_user;

				$query = "SELECT id,login,access_level,pwd_hash
	            FROM ttrss_users WHERE
					login = '$login'";

				if (defined('AUTO_CREATE_USER') && AUTO_CREATE_USER
						&& $_SERVER["REMOTE_USER"]) {
					$result = db_query($link, $query);

					// First login ?
					if (db_num_rows($result) == 0) {
						$salt = substr(bin2hex(get_random_bytes(125)), 0, 250);
						$pwd_hash = encrypt_password($password, $salt, true);

						$query2 = "INSERT INTO ttrss_users
								(login,access_level,last_login,created,pwd_hash,salt)
								VALUES ('$login', 0, null, NOW(), '$pwd_hash','$salt')";
						db_query($link, $query2);
					}
				}

			} else if (get_schema_version($link) > 87) {
				$result = db_query($link, "SELECT salt FROM ttrss_users WHERE
					login = '$login'");

				if (db_num_rows($result) != 1) {
					return false;
				}

				$salt = db_fetch_result($result, 0, "salt");

				if ($salt == "") {

					$query = "SELECT id,login,access_level,pwd_hash
		            FROM ttrss_users WHERE
						login = '$login' AND (pwd_hash = '$pwd_hash1' OR
						pwd_hash = '$pwd_hash2')";

					// verify and upgrade password to new salt base

					$result = db_query($link, $query);

					if (db_num_rows($result) == 1) {
						// upgrade password to MODE2

						$salt = substr(bin2hex(get_random_bytes(125)), 0, 250);
						$pwd_hash = encrypt_password($password, $salt, true);

						db_query($link, "UPDATE ttrss_users SET
							pwd_hash = '$pwd_hash', salt = '$salt' WHERE login = '$login'");

						$query = "SELECT id,login,access_level,pwd_hash
			            FROM ttrss_users WHERE
							login = '$login' AND pwd_hash = '$pwd_hash'";

					} else {
						return false;
					}

				} else {

					$pwd_hash = encrypt_password($password, $salt, true);

					$query = "SELECT id,login,access_level,pwd_hash
			         FROM ttrss_users WHERE
						login = '$login' AND pwd_hash = '$pwd_hash'";

				}
			} else {
				$query = "SELECT id,login,access_level,pwd_hash
		         FROM ttrss_users WHERE
					login = '$login' AND (pwd_hash = '$pwd_hash1' OR
						pwd_hash = '$pwd_hash2')";
			}

			$result = db_query($link, $query);

			if (db_num_rows($result) == 1) {
				$_SESSION["uid"] = db_fetch_result($result, 0, "id");
				$_SESSION["name"] = db_fetch_result($result, 0, "login");
				$_SESSION["access_level"] = db_fetch_result($result, 0, "access_level");
				$_SESSION["csrf_token"] = sha1(uniqid(rand(), true));

				db_query($link, "UPDATE ttrss_users SET last_login = NOW() WHERE id = " .
					$_SESSION["uid"]);


				// LemonLDAP can send user informations via HTTP HEADER
				if (defined('AUTO_CREATE_USER') && AUTO_CREATE_USER){
					// update user name
					$fullname = $_SERVER['HTTP_USER_NAME'] ? $_SERVER['HTTP_USER_NAME'] : $_SERVER['AUTHENTICATE_CN'];
					if ($fullname){
						$fullname = db_escape_string($fullname);
						db_query($link, "UPDATE ttrss_users SET full_name = '$fullname' WHERE id = " .
							$_SESSION["uid"]);
					}
					// update user mail
					$email = $_SERVER['HTTP_USER_MAIL'] ? $_SERVER['HTTP_USER_MAIL'] : $_SERVER['AUTHENTICATE_MAIL'];
					if ($email){
						$email = db_escape_string($email);
						db_query($link, "UPDATE ttrss_users SET email = '$email' WHERE id = " .
							$_SESSION["uid"]);
					}
				}

				$_SESSION["ip_address"] = $_SERVER["REMOTE_ADDR"];
				$_SESSION["pwd_hash"] = db_fetch_result($result, 0, "pwd_hash");

				$_SESSION["last_version_check"] = time();

				initialize_user_prefs($link, $_SESSION["uid"]);

				return true;
			}

			return false;

		} else {

			$_SESSION["uid"] = 1;
			$_SESSION["name"] = "admin";
			$_SESSION["access_level"] = 10;

			if (!$_SESSION["csrf_token"]) {
				$_SESSION["csrf_token"] = sha1(uniqid(rand(), true));
			}

			$_SESSION["ip_address"] = $_SERVER["REMOTE_ADDR"];

			initialize_user_prefs($link, $_SESSION["uid"]);

			return true;
		}
	}

	function make_password($length = 8) {

		$password = "";
		$possible = "0123456789abcdfghjkmnpqrstvwxyzABCDFGHJKMNPQRSTVWXYZ";

   	$i = 0;

		while ($i < $length) {
			$char = substr($possible, mt_rand(0, strlen($possible)-1), 1);

			if (!strstr($password, $char)) {
				$password .= $char;
				$i++;
			}
		}
		return $password;
	}

	// this is called after user is created to initialize default feeds, labels
	// or whatever else

	// user preferences are checked on every login, not here

	function initialize_user($link, $uid) {

		db_query($link, "insert into ttrss_feeds (owner_uid,title,feed_url)
			values ('$uid', 'Tiny Tiny RSS: New Releases',
			'http://tt-rss.org/releases.rss')");

		db_query($link, "insert into ttrss_feeds (owner_uid,title,feed_url)
			values ('$uid', 'Tiny Tiny RSS: Forum',
				'http://tt-rss.org/forum/rss.php')");
	}

	function logout_user() {
		session_destroy();
		if (isset($_COOKIE[session_name()])) {
		   setcookie(session_name(), '', time()-42000, '/');
		}
	}

	function validate_csrf($csrf_token) {
		return $csrf_token == $_SESSION['csrf_token'];
	}

	function validate_session($link) {
		if (SINGLE_USER_MODE) return true;

		$check_ip = $_SESSION['ip_address'];

		switch (SESSION_CHECK_ADDRESS) {
		case 0:
			$check_ip = '';
			break;
		case 1:
			$check_ip = substr($check_ip, 0, strrpos($check_ip, '.')+1);
			break;
		case 2:
			$check_ip = substr($check_ip, 0, strrpos($check_ip, '.'));
			$check_ip = substr($check_ip, 0, strrpos($check_ip, '.')+1);
			break;
		};

		if ($check_ip && strpos($_SERVER['REMOTE_ADDR'], $check_ip) !== 0) {
			$_SESSION["login_error_msg"] =
				__("Session failed to validate (incorrect IP)");
			return false;
		}

		if ($_SESSION["ref_schema_version"] != get_schema_version($link, true))
			return false;

		if ($_SESSION["uid"]) {

			$result = db_query($link,
				"SELECT pwd_hash FROM ttrss_users WHERE id = '".$_SESSION["uid"]."'");

			$pwd_hash = db_fetch_result($result, 0, "pwd_hash");

			if ($pwd_hash != $_SESSION["pwd_hash"]) {
				return false;
			}
		}

/*		if ($_SESSION["cookie_lifetime"] && $_SESSION["uid"]) {

			//print_r($_SESSION);

			if (time() > $_SESSION["cookie_lifetime"]) {
				return false;
			}
		} */

		return true;
	}

	function login_sequence($link, $mobile = false) {
		$_SESSION["prefs_cache"] = array();

		if (!SINGLE_USER_MODE) {

			$login_action = $_POST["login_action"];

			# try to authenticate user if called from login form
			if ($login_action == "do_login") {
				$login = db_escape_string($_POST["login"]);
				$password = $_POST["password"];
				$remember_me = $_POST["remember_me"];

				if (authenticate_user($link, $login, $password)) {
					$_POST["password"] = "";

					$_SESSION["language"] = $_POST["language"];
					$_SESSION["ref_schema_version"] = get_schema_version($link, true);
					$_SESSION["bw_limit"] = !!$_POST["bw_limit"];

					if ($_POST["profile"]) {

						$profile = db_escape_string($_POST["profile"]);

						$result = db_query($link, "SELECT id FROM ttrss_settings_profiles
							WHERE id = '$profile' AND owner_uid = " . $_SESSION["uid"]);

						if (db_num_rows($result) != 0) {
							$_SESSION["profile"] = $profile;
							$_SESSION["prefs_cache"] = array();
						}
					}

					if ($_REQUEST['return']) {
						header("Location: " . $_REQUEST['return']);
					} else {
						header("Location: " . $_SERVER["REQUEST_URI"]);
					}

					exit;

					return;
				} else {
					$_SESSION["login_error_msg"] = __("Incorrect username or password");
				}
			}

			if (!$_SESSION["uid"] || !validate_session($link)) {

				if (get_remote_user($link) && AUTO_LOGIN) {
				    authenticate_user($link, get_remote_user($link), null);
				    $_SESSION["ref_schema_version"] = get_schema_version($link, true);
				} else {
				    render_login_form($link, $mobile);
				    //header("Location: login.php");
				    exit;
				}
			} else {
				/* bump login timestamp */
				db_query($link, "UPDATE ttrss_users SET last_login = NOW() WHERE id = " .
					$_SESSION["uid"]);

				if ($_SESSION["language"] && SESSION_COOKIE_LIFETIME > 0) {
					setcookie("ttrss_lang", $_SESSION["language"],
						time() + SESSION_COOKIE_LIFETIME);
				}

				// try to remove possible duplicates from feed counter cache
//				ccache_cleanup($link, $_SESSION["uid"]);
			}

		} else {
			return authenticate_user($link, "admin", null);
		}
	}

	function truncate_string($str, $max_len, $suffix = '&hellip;') {
		if (mb_strlen($str, "utf-8") > $max_len - 3) {
			return mb_substr($str, 0, $max_len, "utf-8") . $suffix;
		} else {
			return $str;
		}
	}

	function theme_image($link, $filename) {
		if ($link) {
			$theme_path = get_user_theme_path($link);

			if ($theme_path && is_file($theme_path.$filename)) {
				return $theme_path.$filename;
			} else {
				return $filename;
			}
		} else {
			return $filename;
		}
	}

	function get_user_theme($link) {

		if (get_schema_version($link) >= 63 && $_SESSION["uid"]) {
			$theme_name = get_pref($link, "_THEME_ID");
			if (is_dir("themes/$theme_name")) {
				return $theme_name;
			} else {
				return '';
			}
		} else {
			return '';
		}

	}

	function get_user_theme_path($link) {
		$theme_path = '';

		if (get_schema_version($link) >= 63 && $_SESSION["uid"]) {
			$theme_name = get_pref($link, "_THEME_ID");

			if ($theme_name && is_dir("themes/$theme_name")) {
				$theme_path = "themes/$theme_name/";
			} else {
				$theme_name = '';
			}
		} else {
			$theme_path = '';
		}

		if ($theme_path) {
			if (is_file("$theme_path/theme.ini")) {
				$ini = parse_ini_file("$theme_path/theme.ini", true);
				if ($ini['theme']['version'] >= THEME_VERSION_REQUIRED) {
					return $theme_path;
				}
			}
		}
		return '';
	}

	function get_user_theme_options($link) {
		$t = get_user_theme_path($link);

		if ($t) {
			if (is_file("$t/theme.ini")) {
				$ini = parse_ini_file("$t/theme.ini", true);
				if ($ini['theme']['version']) {
					return $ini['theme']['options'];
				}
			}
		}
		return '';
	}

	function print_theme_includes($link) {

		$t = get_user_theme_path($link);
		$time = time();

		if ($t) {
			print "<link rel=\"stylesheet\" type=\"text/css\"
				href=\"$t/theme.css?$time \">";
			if (file_exists("$t/theme.js")) {
				print "<script type=\"text/javascript\" src=\"$t/theme.js?$time\">
					</script>";
			}
		}
	}

	function get_all_themes() {
		$themes = glob("themes/*");

		asort($themes);

		$rv = array();

		foreach ($themes as $t) {
			if (is_file("$t/theme.ini")) {
				$ini = parse_ini_file("$t/theme.ini", true);
				if ($ini['theme']['version'] >= THEME_VERSION_REQUIRED &&
							!$ini['theme']['disabled']) {
					$entry = array();
					$entry["path"] = $t;
					$entry["base"] = basename($t);
					$entry["name"] = $ini['theme']['name'];
					$entry["version"] = $ini['theme']['version'];
					$entry["author"] = $ini['theme']['author'];
					$entry["options"] = $ini['theme']['options'];
					array_push($rv, $entry);
				}
			}
		}

		return $rv;
	}

	function convert_timestamp($timestamp, $source_tz, $dest_tz) {

		try {
			$source_tz = new DateTimeZone($source_tz);
		} catch (Exception $e) {
			$source_tz = new DateTimeZone('UTC');
		}

		try {
			$dest_tz = new DateTimeZone($dest_tz);
		} catch (Exception $e) {
			$dest_tz = new DateTimeZone('UTC');
		}

		$dt = new DateTime(date('Y-m-d H:i:s', $timestamp), $source_tz);
		return $dt->format('U') + $dest_tz->getOffset($dt);
	}

	function make_local_datetime($link, $timestamp, $long, $owner_uid = false,
					$no_smart_dt = false) {

		if (!$owner_uid) $owner_uid = $_SESSION['uid'];
		if (!$timestamp) $timestamp = '1970-01-01 0:00';

		global $utc_tz;
		global $tz_offset;

		# We store date in UTC internally
		$dt = new DateTime($timestamp, $utc_tz);

		if ($tz_offset == -1) {

			$user_tz_string = get_pref($link, 'USER_TIMEZONE', $owner_uid);

			try {
				$user_tz = new DateTimeZone($user_tz_string);
			} catch (Exception $e) {
				$user_tz = $utc_tz;
			}

			$tz_offset = $user_tz->getOffset($dt);
		}

		$user_timestamp = $dt->format('U') + $tz_offset;

		if (!$no_smart_dt) {
			return smart_date_time($link, $user_timestamp,
				$tz_offset, $owner_uid);
		} else {
			if ($long)
				$format = get_pref($link, 'LONG_DATE_FORMAT', $owner_uid);
			else
				$format = get_pref($link, 'SHORT_DATE_FORMAT', $owner_uid);

			return date($format, $user_timestamp);
		}
	}

	function smart_date_time($link, $timestamp, $tz_offset = 0, $owner_uid = false) {
		if (!$owner_uid) $owner_uid = $_SESSION['uid'];

		if (date("Y.m.d", $timestamp) == date("Y.m.d", time() + $tz_offset)) {
			return date("G:i", $timestamp);
		} else if (date("Y", $timestamp) == date("Y", time() + $tz_offset)) {
			$format = get_pref($link, 'SHORT_DATE_FORMAT', $owner_uid);
			return date($format, $timestamp);
		} else {
			$format = get_pref($link, 'LONG_DATE_FORMAT', $owner_uid);
			return date($format, $timestamp);
		}
	}

	function sql_bool_to_bool($s) {
		if ($s == "t" || $s == "1" || $s == "true") {
			return true;
		} else {
			return false;
		}
	}

	function bool_to_sql_bool($s) {
		if ($s) {
			return "true";
		} else {
			return "false";
		}
	}

	// Session caching removed due to causing wrong redirects to upgrade
	// script when get_schema_version() is called on an obsolete session
	// created on a previous schema version.
	function get_schema_version($link, $nocache = false) {
		global $schema_version;

		if (!$schema_version) {
			$result = db_query($link, "SELECT schema_version FROM ttrss_version");
			$version = db_fetch_result($result, 0, "schema_version");
			$schema_version = $version;
			return $version;
		} else {
			return $schema_version;
		}
	}

	function sanity_check($link) {
		require_once 'errors.php';

		$error_code = 0;
		$schema_version = get_schema_version($link, true);

		if ($schema_version != SCHEMA_VERSION) {
			$error_code = 5;
		}

		if (DB_TYPE == "mysql") {
			$result = db_query($link, "SELECT true", false);
			if (db_num_rows($result) != 1) {
				$error_code = 10;
			}
		}

		if (db_escape_string("testTEST") != "testTEST") {
			$error_code = 12;
		}

		return array("code" => $error_code, "message" => $ERRORS[$error_code]);
	}

	function file_is_locked($filename) {
		if (function_exists('flock')) {
			$fp = @fopen(LOCK_DIRECTORY . "/$filename", "r");
			if ($fp) {
				if (flock($fp, LOCK_EX | LOCK_NB)) {
					flock($fp, LOCK_UN);
					fclose($fp);
					return false;
				}
				fclose($fp);
				return true;
			} else {
				return false;
			}
		}
		return true; // consider the file always locked and skip the test
	}

	function make_lockfile($filename) {
		$fp = fopen(LOCK_DIRECTORY . "/$filename", "w");

		if (flock($fp, LOCK_EX | LOCK_NB)) {
			if (function_exists('posix_getpid')) {
				fwrite($fp, posix_getpid() . "\n");
			}
			return $fp;
		} else {
			return false;
		}
	}

	function make_stampfile($filename) {
		$fp = fopen(LOCK_DIRECTORY . "/$filename", "w");

		if (flock($fp, LOCK_EX | LOCK_NB)) {
			fwrite($fp, time() . "\n");
			flock($fp, LOCK_UN);
			fclose($fp);
			return true;
		} else {
			return false;
		}
	}

	function sql_random_function() {
		if (DB_TYPE == "mysql") {
			return "RAND()";
		} else {
			return "RANDOM()";
		}
	}

	function catchup_feed($link, $feed, $cat_view, $owner_uid = false) {

			if (!$owner_uid) $owner_uid = $_SESSION['uid'];

			//if (preg_match("/^-?[0-9][0-9]*$/", $feed) != false) {

			if (is_numeric($feed)) {
				if ($cat_view) {

					if ($feed >= 0) {

						if ($feed > 0) {
							$cat_qpart = "cat_id = '$feed'";
						} else {
							$cat_qpart = "cat_id IS NULL";
						}

						$tmp_result = db_query($link, "SELECT id
							FROM ttrss_feeds WHERE $cat_qpart AND owner_uid = $owner_uid");

						while ($tmp_line = db_fetch_assoc($tmp_result)) {

							$tmp_feed = $tmp_line["id"];

							db_query($link, "UPDATE ttrss_user_entries
								SET unread = false,last_read = NOW()
								WHERE feed_id = '$tmp_feed' AND owner_uid = $owner_uid");
						}
					} else if ($feed == -2) {

						db_query($link, "UPDATE ttrss_user_entries
							SET unread = false,last_read = NOW() WHERE (SELECT COUNT(*)
								FROM ttrss_user_labels2 WHERE article_id = ref_id) > 0
							AND unread = true AND owner_uid = $owner_uid");
					}

				} else if ($feed > 0) {

					db_query($link, "UPDATE ttrss_user_entries
							SET unread = false,last_read = NOW()
							WHERE feed_id = '$feed' AND owner_uid = $owner_uid");

				} else if ($feed < 0 && $feed > -10) { // special, like starred

					if ($feed == -1) {
						db_query($link, "UPDATE ttrss_user_entries
							SET unread = false,last_read = NOW()
							WHERE marked = true AND owner_uid = $owner_uid");
					}

					if ($feed == -2) {
						db_query($link, "UPDATE ttrss_user_entries
							SET unread = false,last_read = NOW()
							WHERE published = true AND owner_uid = $owner_uid");
					}

					if ($feed == -3) {

						$intl = get_pref($link, "FRESH_ARTICLE_MAX_AGE");

						if (DB_TYPE == "pgsql") {
							$match_part = "updated > NOW() - INTERVAL '$intl hour' ";
						} else {
							$match_part = "updated > DATE_SUB(NOW(),
								INTERVAL $intl HOUR) ";
						}

						$result = db_query($link, "SELECT id FROM ttrss_entries,
							ttrss_user_entries WHERE $match_part AND
							unread = true AND
						  	ttrss_user_entries.ref_id = ttrss_entries.id AND
							owner_uid = $owner_uid");

						$affected_ids = array();

						while ($line = db_fetch_assoc($result)) {
							array_push($affected_ids, $line["id"]);
						}

						catchupArticlesById($link, $affected_ids, 0);
					}

					if ($feed == -4) {
						db_query($link, "UPDATE ttrss_user_entries
							SET unread = false,last_read = NOW()
							WHERE owner_uid = $owner_uid");
					}

				} else if ($feed < -10) { // label

					$label_id = -$feed - 11;

					db_query($link, "UPDATE ttrss_user_entries, ttrss_user_labels2
						SET unread = false, last_read = NOW()
							WHERE label_id = '$label_id' AND unread = true
							AND owner_uid = '$owner_uid' AND ref_id = article_id");

				}

				ccache_update($link, $feed, $owner_uid, $cat_view);

			} else { // tag
				db_query($link, "BEGIN");

				$tag_name = db_escape_string($feed);

				$result = db_query($link, "SELECT post_int_id FROM ttrss_tags
					WHERE tag_name = '$tag_name' AND owner_uid = $owner_uid");

				while ($line = db_fetch_assoc($result)) {
					db_query($link, "UPDATE ttrss_user_entries SET
						unread = false, last_read = NOW()
						WHERE int_id = " . $line["post_int_id"]);
				}
				db_query($link, "COMMIT");
			}
	}

	function getAllCounters($link, $omode = "flc", $active_feed = false) {

		if (!$omode) $omode = "flc";

		$data = getGlobalCounters($link);

		$data = array_merge($data, getVirtCounters($link));

		if (strchr($omode, "l")) $data = array_merge($data, getLabelCounters($link));
		if (strchr($omode, "f")) $data = array_merge($data, getFeedCounters($link, $active_feed));
		if (strchr($omode, "t")) $data = array_merge($data, getTagCounters($link));
		if (strchr($omode, "c")) $data = array_merge($data, getCategoryCounters($link));

		return $data;
	}

	function getCategoryTitle($link, $cat_id) {

		if ($cat_id == -1) {
			return __("Special");
		} else if ($cat_id == -2) {
			return __("Labels");
		} else {

			$result = db_query($link, "SELECT title FROM ttrss_feed_categories WHERE
				id = '$cat_id'");

			if (db_num_rows($result) == 1) {
				return db_fetch_result($result, 0, "title");
			} else {
				return "Uncategorized";
			}
		}
	}


	function getCategoryCounters($link) {
		$ret_arr = array();

		/* Labels category */

		$cv = array("id" => -2, "kind" => "cat",
			"counter" => getCategoryUnread($link, -2));

		array_push($ret_arr, $cv);

		$age_qpart = getMaxAgeSubquery();

		$result = db_query($link, "SELECT id AS cat_id, value AS unread
			FROM ttrss_feed_categories, ttrss_cat_counters_cache
			WHERE ttrss_cat_counters_cache.feed_id = id AND
			ttrss_feed_categories.owner_uid = " . $_SESSION["uid"]);

		while ($line = db_fetch_assoc($result)) {
			$line["cat_id"] = (int) $line["cat_id"];

			$cv = array("id" => $line["cat_id"], "kind" => "cat",
				"counter" => $line["unread"]);

			array_push($ret_arr, $cv);
		}

		/* Special case: NULL category doesn't actually exist in the DB */

		$cv = array("id" => 0, "kind" => "cat",
			"counter" => ccache_find($link, 0, $_SESSION["uid"], true));

		array_push($ret_arr, $cv);

		return $ret_arr;
	}

	function getCategoryUnread($link, $cat, $owner_uid = false) {

		if (!$owner_uid) $owner_uid = $_SESSION["uid"];

		if ($cat >= 0) {

			if ($cat != 0) {
				$cat_query = "cat_id = '$cat'";
			} else {
				$cat_query = "cat_id IS NULL";
			}

			$age_qpart = getMaxAgeSubquery();

			$result = db_query($link, "SELECT id FROM ttrss_feeds WHERE $cat_query
					AND owner_uid = " . $owner_uid);

			$cat_feeds = array();
			while ($line = db_fetch_assoc($result)) {
				array_push($cat_feeds, "feed_id = " . $line["id"]);
			}

			if (count($cat_feeds) == 0) return 0;

			$match_part = implode(" OR ", $cat_feeds);

			$result = db_query($link, "SELECT COUNT(int_id) AS unread
				FROM ttrss_user_entries,ttrss_entries
				WHERE	unread = true AND ($match_part) AND id = ref_id
				AND $age_qpart AND owner_uid = " . $owner_uid);

			$unread = 0;

			# this needs to be rewritten
			while ($line = db_fetch_assoc($result)) {
				$unread += $line["unread"];
			}

			return $unread;
		} else if ($cat == -1) {
			return getFeedUnread($link, -1) + getFeedUnread($link, -2) + getFeedUnread($link, -3) + getFeedUnread($link, 0);
		} else if ($cat == -2) {

			$result = db_query($link, "
				SELECT COUNT(unread) AS unread FROM
					ttrss_user_entries, ttrss_labels2, ttrss_user_labels2, ttrss_feeds
				WHERE label_id = ttrss_labels2.id AND article_id = ref_id AND
					ttrss_labels2.owner_uid = '$owner_uid'
					AND unread = true AND feed_id = ttrss_feeds.id
					AND ttrss_user_entries.owner_uid = '$owner_uid'");

			$unread = db_fetch_result($result, 0, "unread");

			return $unread;

		}
	}

	function getMaxAgeSubquery($days = COUNTERS_MAX_AGE) {
		if (DB_TYPE == "pgsql") {
			return "ttrss_entries.date_updated >
				NOW() - INTERVAL '$days days'";
		} else {
			return "ttrss_entries.date_updated >
				DATE_SUB(NOW(), INTERVAL $days DAY)";
		}
	}

	function getFeedUnread($link, $feed, $is_cat = false) {
		return getFeedArticles($link, $feed, $is_cat, true, $_SESSION["uid"]);
	}

	function getLabelUnread($link, $label_id, $owner_uid = false) {
		if (!$owner_uid) $owner_uid = $_SESSION["uid"];

		$result = db_query($link, "
			SELECT COUNT(unread) AS unread FROM
				ttrss_user_entries, ttrss_labels2, ttrss_user_labels2, ttrss_feeds
			WHERE label_id = ttrss_labels2.id AND article_id = ref_id AND
				ttrss_labels2.owner_uid = '$owner_uid' AND ttrss_labels2.id = '$label_id'
				AND unread = true AND feed_id = ttrss_feeds.id
				AND ttrss_user_entries.owner_uid = '$owner_uid'");

		if (db_num_rows($result) != 0) {
			return db_fetch_result($result, 0, "unread");
		} else {
			return 0;
		}
	}

	function getFeedArticles($link, $feed, $is_cat = false, $unread_only = false,
		$owner_uid = false) {

		$n_feed = (int) $feed;

		if (!$owner_uid) $owner_uid = $_SESSION["uid"];

		if ($unread_only) {
			$unread_qpart = "unread = true";
		} else {
			$unread_qpart = "true";
		}

		$age_qpart = getMaxAgeSubquery();

		if ($is_cat) {
			return getCategoryUnread($link, $n_feed, $owner_uid);
		} if ($feed != "0" && $n_feed == 0) {

			$feed = db_escape_string($feed);

			$result = db_query($link, "SELECT SUM((SELECT COUNT(int_id)
				FROM ttrss_user_entries,ttrss_entries WHERE int_id = post_int_id
					AND ref_id = id AND $age_qpart
					AND $unread_qpart)) AS count FROM ttrss_tags
				WHERE owner_uid = $owner_uid AND tag_name = '$feed'");
			return db_fetch_result($result, 0, "count");

		} else if ($n_feed == -1) {
			$match_part = "marked = true";
		} else if ($n_feed == -2) {
			$match_part = "published = true";
		} else if ($n_feed == -3) {
			$match_part = "unread = true AND score >= 0";

			$intl = get_pref($link, "FRESH_ARTICLE_MAX_AGE", $owner_uid);

			if (DB_TYPE == "pgsql") {
				$match_part .= " AND updated > NOW() - INTERVAL '$intl hour' ";
			} else {
				$match_part .= " AND updated > DATE_SUB(NOW(), INTERVAL $intl HOUR) ";
			}
		} else if ($n_feed == -4) {
			$match_part = "true";
		} else if ($n_feed >= 0) {

			if ($n_feed != 0) {
				$match_part = "feed_id = '$n_feed'";
			} else {
				$match_part = "feed_id IS NULL";
			}

		} else if ($feed < -10) {

			$label_id = -$feed - 11;

			return getLabelUnread($link, $label_id, $owner_uid);

		}

		if ($match_part) {

			if ($n_feed != 0) {
				$from_qpart = "ttrss_user_entries,ttrss_feeds,ttrss_entries";
				$feeds_qpart = "ttrss_user_entries.feed_id = ttrss_feeds.id AND";
			} else {
				$from_qpart = "ttrss_user_entries,ttrss_entries";
				$feeds_qpart = '';
			}

			$query = "SELECT count(int_id) AS unread
				FROM $from_qpart WHERE
				ttrss_user_entries.ref_id = ttrss_entries.id AND
				$age_qpart AND
				$feeds_qpart
				$unread_qpart AND ($match_part) AND ttrss_user_entries.owner_uid = $owner_uid";

			$result = db_query($link, $query);

		} else {

			$result = db_query($link, "SELECT COUNT(post_int_id) AS unread
				FROM ttrss_tags,ttrss_user_entries,ttrss_entries
				WHERE tag_name = '$feed' AND post_int_id = int_id AND ref_id = ttrss_entries.id
				AND $unread_qpart AND $age_qpart AND
					ttrss_tags.owner_uid = " . $owner_uid);
		}

		$unread = db_fetch_result($result, 0, "unread");

		return $unread;
	}

	function getGlobalUnread($link, $user_id = false) {

		if (!$user_id) {
			$user_id = $_SESSION["uid"];
		}

		$result = db_query($link, "SELECT SUM(value) AS c_id FROM ttrss_counters_cache
			WHERE owner_uid = '$user_id' AND feed_id > 0");

		$c_id = db_fetch_result($result, 0, "c_id");

		return $c_id;
	}

	function getGlobalCounters($link, $global_unread = -1) {
		$ret_arr = array();

		if ($global_unread == -1) {
			$global_unread = getGlobalUnread($link);
		}

		$cv = array("id" => "global-unread",
			"counter" => $global_unread);

		array_push($ret_arr, $cv);

		$result = db_query($link, "SELECT COUNT(id) AS fn FROM
			ttrss_feeds WHERE owner_uid = " . $_SESSION["uid"]);

		$subscribed_feeds = db_fetch_result($result, 0, "fn");

		$cv = array("id" => "subscribed-feeds",
			"counter" => $subscribed_feeds);

		array_push($ret_arr, $cv);

		return $ret_arr;
	}

	function getTagCounters($link) {

		$ret_arr = array();

		$age_qpart = getMaxAgeSubquery();

		$result = db_query($link, "SELECT tag_name,SUM((SELECT COUNT(int_id)
			FROM ttrss_user_entries,ttrss_entries WHERE int_id = post_int_id
				AND ref_id = id AND $age_qpart
				AND unread = true)) AS count FROM ttrss_tags
				WHERE owner_uid = ".$_SESSION['uid']." GROUP BY tag_name
				ORDER BY count DESC LIMIT 55");

		$tags = array();

		while ($line = db_fetch_assoc($result)) {
			$tags[$line["tag_name"]] += $line["count"];
		}

		foreach (array_keys($tags) as $tag) {
			$unread = $tags[$tag];
			$tag = htmlspecialchars($tag);

			$cv = array("id" => $tag,
				"kind" => "tag",
				"counter" => $unread);

			array_push($ret_arr, $cv);
		}

		return $ret_arr;
	}

	function getVirtCounters($link) {

		$ret_arr = array();

		for ($i = 0; $i >= -4; $i--) {

			$count = getFeedUnread($link, $i);

			$cv = array("id" => $i,
				"counter" => $count);

//			if (get_pref($link, 'EXTENDED_FEEDLIST'))
//				$cv["xmsg"] = getFeedArticles($link, $i)." ".__("total");

			array_push($ret_arr, $cv);
		}

		return $ret_arr;
	}

	function getLabelCounters($link, $descriptions = false) {

		$ret_arr = array();

		$age_qpart = getMaxAgeSubquery();

		$owner_uid = $_SESSION["uid"];

		$result = db_query($link, "SELECT id, caption FROM ttrss_labels2
			WHERE owner_uid = '$owner_uid'");

		while ($line = db_fetch_assoc($result)) {

			$id = -$line["id"] - 11;

			$label_name = $line["caption"];
			$count = getFeedUnread($link, $id);

			$cv = array("id" => $id,
				"counter" => $count);

			if ($descriptions)
				$cv["description"] = $label_name;

//			if (get_pref($link, 'EXTENDED_FEEDLIST'))
//				$cv["xmsg"] = getFeedArticles($link, $id)." ".__("total");

			array_push($ret_arr, $cv);
		}

		return $ret_arr;
	}

	function getFeedCounters($link, $active_feed = false) {

		$ret_arr = array();

		$age_qpart = getMaxAgeSubquery();

		$query = "SELECT ttrss_feeds.id,
				ttrss_feeds.title,
				".SUBSTRING_FOR_DATE."(ttrss_feeds.last_updated,1,19) AS last_updated,
				last_error, value AS count
			FROM ttrss_feeds, ttrss_counters_cache
			WHERE ttrss_feeds.owner_uid = ".$_SESSION["uid"]."
				AND ttrss_counters_cache.feed_id = id";

		$result = db_query($link, $query);
		$fctrs_modified = false;

		while ($line = db_fetch_assoc($result)) {

			$id = $line["id"];
			$count = $line["count"];
			$last_error = htmlspecialchars($line["last_error"]);

			$last_updated = make_local_datetime($link, $line['last_updated'], false);

			$has_img = feed_has_icon($id);

			if (date('Y') - date('Y', strtotime($line['last_updated'])) > 2)
				$last_updated = '';

			$cv = array("id" => $id,
				"updated" => $last_updated,
				"counter" => $count,
				"has_img" => (int) $has_img);

			if ($last_error)
				$cv["error"] = $last_error;

//			if (get_pref($link, 'EXTENDED_FEEDLIST'))
//				$cv["xmsg"] = getFeedArticles($link, $id)." ".__("total");

			if ($active_feed && $id == $active_feed)
				$cv["title"] = truncate_string($line["title"], 30);

			array_push($ret_arr, $cv);

		}

		return $ret_arr;
	}

	function get_pgsql_version($link) {
		$result = db_query($link, "SELECT version() AS version");
		$version = explode(" ", db_fetch_result($result, 0, "version"));
		return $version[1];
	}

	/**
	 * @return integer Status code:
	 *                 0 - OK, Feed already exists
	 *                 1 - OK, Feed added
	 *                 2 - Invalid URL
	 *                 3 - URL content is HTML, no feeds available
	 *                 4 - URL content is HTML which contains multiple feeds.
	 *                     Here you should call extractfeedurls in rpc-backend
	 *                     to get all possible feeds.
	 *                 5 - Couldn't download the URL content.
	 */
	function subscribe_to_feed($link, $url, $cat_id = 0,
			$auth_login = '', $auth_pass = '', $need_auth = false) {

		require_once "include/rssfuncs.php";

		$url = fix_url($url);

		if (!$url || !validate_feed_url($url)) return 2;

		$update_method = 0;

		$result = db_query($link, "SELECT twitter_oauth FROM ttrss_users
			WHERE id = ".$_SESSION['uid']);

		$has_oauth = db_fetch_result($result, 0, 'twitter_oauth');

		if (!$need_auth || !$has_oauth || strpos($url, '://api.twitter.com') === false) {
			if (!fetch_file_contents($url, false, $auth_login, $auth_pass)) return 5;

			if (url_is_html($url, $auth_login, $auth_pass)) {
				$feedUrls = get_feeds_from_html($url, $auth_login, $auth_pass);
				if (count($feedUrls) == 0) {
					return 3;
				} else if (count($feedUrls) > 1) {
					return 4;
				}
				//use feed url as new URL
				$url = key($feedUrls);
			}

			} else {
				if (!fetch_twitter_rss($link, $url, $_SESSION['uid']))
					return 5;

				$update_method = 3;
			}
		if ($cat_id == "0" || !$cat_id) {
			$cat_qpart = "NULL";
		} else {
			$cat_qpart = "'$cat_id'";
		}

		$result = db_query($link,
			"SELECT id FROM ttrss_feeds
			WHERE feed_url = '$url' AND owner_uid = ".$_SESSION["uid"]);

		if (db_num_rows($result) == 0) {
			$result = db_query($link,
				"INSERT INTO ttrss_feeds
					(owner_uid,feed_url,title,cat_id, auth_login,auth_pass,update_method)
				VALUES ('".$_SESSION["uid"]."', '$url',
				'[Unknown]', $cat_qpart, '$auth_login', '$auth_pass', '$update_method')");

			$result = db_query($link,
				"SELECT id FROM ttrss_feeds WHERE feed_url = '$url'
					AND owner_uid = " . $_SESSION["uid"]);

			$feed_id = db_fetch_result($result, 0, "id");

			if ($feed_id) {
				update_rss_feed($link, $feed_id, true);
			}

			return 1;
		} else {
			return 0;
		}
	}

	function print_feed_select($link, $id, $default_id = "",
		$attributes = "", $include_all_feeds = true) {

		print "<select id=\"$id\" name=\"$id\" $attributes>";
		if ($include_all_feeds) {
			print "<option value=\"0\">".__('All feeds')."</option>";
		}

		$result = db_query($link, "SELECT id,title FROM ttrss_feeds
			WHERE owner_uid = ".$_SESSION["uid"]." ORDER BY title");

		if (db_num_rows($result) > 0 && $include_all_feeds) {
			print "<option disabled>--------</option>";
		}

		while ($line = db_fetch_assoc($result)) {
			if ($line["id"] == $default_id) {
				$is_selected = "selected=\"1\"";
			} else {
				$is_selected = "";
			}

			$title = truncate_string(htmlspecialchars($line["title"]), 40);

			printf("<option $is_selected value='%d'>%s</option>",
				$line["id"], $title);
		}

		print "</select>";
	}

	function print_feed_cat_select($link, $id, $default_id = "",
		$attributes = "", $include_all_cats = true) {

		print "<select id=\"$id\" name=\"$id\" default=\"$default_id\" onchange=\"catSelectOnChange(this)\" $attributes>";

		if ($include_all_cats) {
			print "<option value=\"0\">".__('Uncategorized')."</option>";
		}

		$result = db_query($link, "SELECT id,title FROM ttrss_feed_categories
			WHERE owner_uid = ".$_SESSION["uid"]." ORDER BY title");

		if (db_num_rows($result) > 0 && $include_all_cats) {
			print "<option disabled=\"1\">--------</option>";
		}

		while ($line = db_fetch_assoc($result)) {
			if ($line["id"] == $default_id) {
				$is_selected = "selected=\"1\"";
			} else {
				$is_selected = "";
			}

			if ($line["title"])
				printf("<option $is_selected value='%d'>%s</option>",
					$line["id"], htmlspecialchars($line["title"]));
		}

#		print "<option value=\"ADD_CAT\">" .__("Add category...") . "</option>";

		print "</select>";
	}

	function checkbox_to_sql_bool($val) {
		return ($val == "on") ? "true" : "false";
	}

	function getFeedCatTitle($link, $id) {
		if ($id == -1) {
			return __("Special");
		} else if ($id < -10) {
			return __("Labels");
		} else if ($id > 0) {
			$result = db_query($link, "SELECT ttrss_feed_categories.title
				FROM ttrss_feeds, ttrss_feed_categories WHERE ttrss_feeds.id = '$id' AND
					cat_id = ttrss_feed_categories.id");
			if (db_num_rows($result) == 1) {
				return db_fetch_result($result, 0, "title");
			} else {
				return __("Uncategorized");
			}
		} else {
			return "getFeedCatTitle($id) failed";
		}

	}

	function getFeedIcon($id) {
		switch ($id) {
		case 0:
			return "images/archive.png";
			break;
		case -1:
			return "images/mark_set.png";
			break;
		case -2:
			return "images/pub_set.png";
			break;
		case -3:
			return "images/fresh.png";
			break;
		case -4:
			return "images/tag.png";
			break;
		default:
			if ($id < -10) {
				return "images/label.png";
			} else {
				if (file_exists(ICONS_DIR . "/$id.ico"))
					return ICONS_URL . "/$id.ico";
			}
			break;
		}
	}

	function getFeedTitle($link, $id) {
		if ($id == -1) {
			return __("Starred articles");
		} else if ($id == -2) {
			return __("Published articles");
		} else if ($id == -3) {
			return __("Fresh articles");
		} else if ($id == -4) {
			return __("All articles");
		} else if ($id === 0 || $id === "0") {
			return __("Archived articles");
		} else if ($id < -10) {
			$label_id = -$id - 11;
			$result = db_query($link, "SELECT caption FROM ttrss_labels2 WHERE id = '$label_id'");
			if (db_num_rows($result) == 1) {
				return db_fetch_result($result, 0, "caption");
			} else {
				return "Unknown label ($label_id)";
			}

		} else if (is_numeric($id) && $id > 0) {
			$result = db_query($link, "SELECT title FROM ttrss_feeds WHERE id = '$id'");
			if (db_num_rows($result) == 1) {
				return db_fetch_result($result, 0, "title");
			} else {
				return "Unknown feed ($id)";
			}
		} else {
			return $id;
		}
	}

	function make_init_params($link) {
		$params = array();

		$params["theme"] = get_user_theme($link);
		$params["theme_options"] = get_user_theme_options($link);

		$params["sign_progress"] = theme_image($link, "images/indicator_white.gif");
		$params["sign_progress_tiny"] = theme_image($link, "images/indicator_tiny.gif");
		$params["sign_excl"] = theme_image($link, "images/sign_excl.png");
		$params["sign_info"] = theme_image($link, "images/sign_info.png");

		foreach (array("ON_CATCHUP_SHOW_NEXT_FEED", "HIDE_READ_FEEDS",
			"ENABLE_FEED_CATS", "FEEDS_SORT_BY_UNREAD", "CONFIRM_FEED_CATCHUP",
			"CDM_AUTO_CATCHUP", "FRESH_ARTICLE_MAX_AGE", "DEFAULT_ARTICLE_LIMIT",
			"HIDE_READ_SHOWS_SPECIAL", "COMBINED_DISPLAY_MODE") as $param) {

				 $params[strtolower($param)] = (int) get_pref($link, $param);
		 }

		$params["icons_url"] = ICONS_URL;
		$params["cookie_lifetime"] = SESSION_COOKIE_LIFETIME;
		$params["default_view_mode"] = get_pref($link, "_DEFAULT_VIEW_MODE");
		$params["default_view_limit"] = (int) get_pref($link, "_DEFAULT_VIEW_LIMIT");
		$params["default_view_order_by"] = get_pref($link, "_DEFAULT_VIEW_ORDER_BY");
		$params["bw_limit"] = (int) $_SESSION["bw_limit"];

		$result = db_query($link, "SELECT MAX(id) AS mid, COUNT(*) AS nf FROM
			ttrss_feeds WHERE owner_uid = " . $_SESSION["uid"]);

		$max_feed_id = db_fetch_result($result, 0, "mid");
		$num_feeds = db_fetch_result($result, 0, "nf");

		$params["max_feed_id"] = (int) $max_feed_id;
		$params["num_feeds"] = (int) $num_feeds;

		$params["collapsed_feedlist"] = (int) get_pref($link, "_COLLAPSED_FEEDLIST");

		$params["csrf_token"] = $_SESSION["csrf_token"];

		return $params;
	}

	function make_runtime_info($link) {
		$data = array();

		$result = db_query($link, "SELECT MAX(id) AS mid, COUNT(*) AS nf FROM
			ttrss_feeds WHERE owner_uid = " . $_SESSION["uid"]);

		$max_feed_id = db_fetch_result($result, 0, "mid");
		$num_feeds = db_fetch_result($result, 0, "nf");

		$data["max_feed_id"] = (int) $max_feed_id;
		$data["num_feeds"] = (int) $num_feeds;

		$data['last_article_id'] = getLastArticleId($link);
		$data['cdm_expanded'] = get_pref($link, 'CDM_EXPANDED');

		if (file_exists(LOCK_DIRECTORY . "/update_daemon.lock")) {

			$data['daemon_is_running'] = (int) file_is_locked("update_daemon.lock");

			if (time() - $_SESSION["daemon_stamp_check"] > 30) {

				$stamp = (int) @file_get_contents(LOCK_DIRECTORY . "/update_daemon.stamp");

				if ($stamp) {
					$stamp_delta = time() - $stamp;

					if ($stamp_delta > 1800) {
						$stamp_check = 0;
					} else {
						$stamp_check = 1;
						$_SESSION["daemon_stamp_check"] = time();
					}

					$data['daemon_stamp_ok'] = $stamp_check;

					$stamp_fmt = date("Y.m.d, G:i", $stamp);

					$data['daemon_stamp'] = $stamp_fmt;
				}
			}
		}

		if ($_SESSION["last_version_check"] + 86400 + rand(-1000, 1000) < time()) {
				$new_version_details = @check_for_update($link);

				$data['new_version_available'] = (int) ($new_version_details != false);

				$_SESSION["last_version_check"] = time();
		}

		return $data;
	}

	function search_to_sql($link, $search, $match_on) {

		$search_query_part = "";

		$keywords = explode(" ", $search);
		$query_keywords = array();

		foreach ($keywords as $k) {
			if (strpos($k, "-") === 0) {
				$k = substr($k, 1);
				$not = "NOT";
			} else {
				$not = "";
			}

			$commandpair = explode(":", mb_strtolower($k), 2);

			if ($commandpair[0] == "note" && $commandpair[1]) {

				if ($commandpair[1] == "true")
					array_push($query_keywords, "($not (note IS NOT NULL AND note != ''))");
				else
					array_push($query_keywords, "($not (note IS NULL OR note = ''))");

			} else if ($commandpair[0] == "star" && $commandpair[1]) {

				if ($commandpair[1] == "true")
					array_push($query_keywords, "($not (marked = true))");
				else
					array_push($query_keywords, "($not (marked = false))");

			} else if ($commandpair[0] == "pub" && $commandpair[1]) {

				if ($commandpair[1] == "true")
					array_push($query_keywords, "($not (published = true))");
				else
					array_push($query_keywords, "($not (published = false))");

			} else if (strpos($k, "@") === 0) {

				$user_tz_string = get_pref($link, 'USER_TIMEZONE', $_SESSION['uid']);
				$orig_ts = strtotime(substr($k, 1));
				$k = date("Y-m-d", convert_timestamp($orig_ts, $user_tz_string, 'UTC'));

				//$k = date("Y-m-d", strtotime(substr($k, 1)));

				array_push($query_keywords, "(".SUBSTRING_FOR_DATE."(updated,1,LENGTH('$k')) $not = '$k')");
			} else if ($match_on == "both") {
				array_push($query_keywords, "(UPPER(ttrss_entries.title) $not LIKE UPPER('%$k%')
						OR UPPER(ttrss_entries.content) $not LIKE UPPER('%$k%'))");
			} else if ($match_on == "title") {
				array_push($query_keywords, "(UPPER(ttrss_entries.title) $not LIKE UPPER('%$k%'))");
			} else if ($match_on == "content") {
				array_push($query_keywords, "(UPPER(ttrss_entries.content) $not LIKE UPPER('%$k%'))");
			}
		}

		$search_query_part = implode("AND", $query_keywords);

		return $search_query_part;
	}


	function queryFeedHeadlines($link, $feed, $limit, $view_mode, $cat_view, $search, $search_mode, $match_on, $override_order = false, $offset = 0, $owner_uid = 0, $filter = false, $since_id = 0) {

		if (!$owner_uid) $owner_uid = $_SESSION["uid"];

		$ext_tables_part = "";

			if ($search) {

				if (SPHINX_ENABLED) {
					$ids = join(",", @sphinx_search($search, 0, 500));

					if ($ids)
						$search_query_part = "ref_id IN ($ids) AND ";
					else
						$search_query_part = "ref_id = -1 AND ";

				} else {
					$search_query_part = search_to_sql($link, $search, $match_on);
					$search_query_part .= " AND ";
				}

			} else {
				$search_query_part = "";
			}

			if ($filter) {
				$filter_query_part = filter_to_sql($filter);
			} else {
				$filter_query_part = "";
			}

			if ($since_id) {
				$since_id_part = "ttrss_entries.id > $since_id AND ";
			} else {
				$since_id_part = "";
			}

			$view_query_part = "";

			if ($view_mode == "adaptive" || $view_query_part == "noscores") {
				if ($search) {
					$view_query_part = " ";
				} else if ($feed != -1) {
					$unread = getFeedUnread($link, $feed, $cat_view);
					if ($unread > 0) {
						$view_query_part = " unread = true AND ";
					}
				}
			}

			if ($view_mode == "marked") {
				$view_query_part = " marked = true AND ";
			}

			if ($view_mode == "published") {
				$view_query_part = " published = true AND ";
			}

			if ($view_mode == "unread") {
				$view_query_part = " unread = true AND ";
			}

			if ($view_mode == "updated") {
				$view_query_part = " (last_read is null and unread = false) AND ";
			}

			if ($limit > 0) {
				$limit_query_part = "LIMIT " . $limit;
			}

			$vfeed_query_part = "";

			// override query strategy and enable feed display when searching globally
			if ($search && $search_mode == "all_feeds") {
				$query_strategy_part = "ttrss_entries.id > 0";
				$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
			/* tags */
			} else if (preg_match("/^-?[0-9][0-9]*$/", $feed) == false) {
				$query_strategy_part = "ttrss_entries.id > 0";
				$vfeed_query_part = "(SELECT title FROM ttrss_feeds WHERE
					id = feed_id) as feed_title,";
			} else if ($feed > 0 && $search && $search_mode == "this_cat") {

				$vfeed_query_part = "ttrss_feeds.title AS feed_title,";

				$tmp_result = false;

				if ($cat_view) {
					$tmp_result = db_query($link, "SELECT id
						FROM ttrss_feeds WHERE cat_id = '$feed'");
				} else {
					$tmp_result = db_query($link, "SELECT id
						FROM ttrss_feeds WHERE cat_id = (SELECT cat_id FROM ttrss_feeds
							WHERE id = '$feed') AND id != '$feed'");
				}

				$cat_siblings = array();

				if (db_num_rows($tmp_result) > 0) {
					while ($p = db_fetch_assoc($tmp_result)) {
						array_push($cat_siblings, "feed_id = " . $p["id"]);
					}

					$query_strategy_part = sprintf("(feed_id = %d OR %s)",
						$feed, implode(" OR ", $cat_siblings));

				} else {
					$query_strategy_part = "ttrss_entries.id > 0";
				}

			} else if ($feed > 0) {

				if ($cat_view) {

					if ($feed > 0) {
						$query_strategy_part = "cat_id = '$feed'";
					} else {
						$query_strategy_part = "cat_id IS NULL";
					}

					$vfeed_query_part = "ttrss_feeds.title AS feed_title,";

				} else {
					$query_strategy_part = "feed_id = '$feed'";
				}
			} else if ($feed == 0 && !$cat_view) { // archive virtual feed
				$query_strategy_part = "feed_id IS NULL";
			} else if ($feed == 0 && $cat_view) { // uncategorized
				$query_strategy_part = "cat_id IS NULL";
				$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
			} else if ($feed == -1) { // starred virtual feed
				$query_strategy_part = "marked = true";
				$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
			} else if ($feed == -2) { // published virtual feed OR labels category

				if (!$cat_view) {
					$query_strategy_part = "published = true";
					$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
				} else {
					$vfeed_query_part = "ttrss_feeds.title AS feed_title,";

					$ext_tables_part = ",ttrss_labels2,ttrss_user_labels2";

					$query_strategy_part = "ttrss_labels2.id = ttrss_user_labels2.label_id AND
						ttrss_user_labels2.article_id = ref_id";

				}

			} else if ($feed == -3) { // fresh virtual feed
				$query_strategy_part = "unread = true AND score >= 0";

				$intl = get_pref($link, "FRESH_ARTICLE_MAX_AGE", $owner_uid);

				if (DB_TYPE == "pgsql") {
					$query_strategy_part .= " AND updated > NOW() - INTERVAL '$intl hour' ";
				} else {
					$query_strategy_part .= " AND updated > DATE_SUB(NOW(), INTERVAL $intl HOUR) ";
				}

				$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
			} else if ($feed == -4) { // all articles virtual feed
				$query_strategy_part = "true";
				$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
			} else if ($feed <= -10) { // labels
				$label_id = -$feed - 11;

				$query_strategy_part = "label_id = '$label_id' AND
					ttrss_labels2.id = ttrss_user_labels2.label_id AND
					ttrss_user_labels2.article_id = ref_id";

				$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
				$ext_tables_part = ",ttrss_labels2,ttrss_user_labels2";

			} else {
				$query_strategy_part = "id > 0"; // dumb
			}

			if (get_pref($link, "SORT_HEADLINES_BY_FEED_DATE", $owner_uid)) {
				$date_sort_field = "updated";
			} else {
				$date_sort_field = "date_entered";
			}

			if (get_pref($link, 'REVERSE_HEADLINES', $owner_uid)) {
				$order_by = "$date_sort_field";
			} else {
				$order_by = "$date_sort_field DESC";
			}

			if ($view_mode != "noscores") {
				$order_by = "score DESC, $order_by";
			}

			if ($override_order) {
				$order_by = $override_order;
			}

			$feed_title = "";

			if ($search) {
				$feed_title = "Search results";
			} else {
				if ($cat_view) {
					$feed_title = getCategoryTitle($link, $feed);
				} else {
					if (is_numeric($feed) && $feed > 0) {
						$result = db_query($link, "SELECT title,site_url,last_error
							FROM ttrss_feeds WHERE id = '$feed' AND owner_uid = $owner_uid");

						$feed_title = db_fetch_result($result, 0, "title");
						$feed_site_url = db_fetch_result($result, 0, "site_url");
						$last_error = db_fetch_result($result, 0, "last_error");
					} else {
						$feed_title = getFeedTitle($link, $feed);
					}
				}
			}

			$content_query_part = "content as content_preview,";

			if (preg_match("/^-?[0-9][0-9]*$/", $feed) != false) {

				if ($feed >= 0) {
					$feed_kind = "Feeds";
				} else {
					$feed_kind = "Labels";
				}

				if ($limit_query_part) {
					$offset_query_part = "OFFSET $offset";
				}

				if ($vfeed_query_part && get_pref($link, 'VFEED_GROUP_BY_FEED', $owner_uid)) {
					if (!$override_order) {
						$order_by = "ttrss_feeds.title, $order_by";
					}
				}

				if ($feed != "0") {
					$from_qpart = "ttrss_entries,ttrss_user_entries,ttrss_feeds$ext_tables_part";
					$feed_check_qpart = "ttrss_user_entries.feed_id = ttrss_feeds.id AND";

				} else {
					$from_qpart = "ttrss_entries,ttrss_user_entries$ext_tables_part
						LEFT JOIN ttrss_feeds ON (feed_id = ttrss_feeds.id)";
				}

				$query = "SELECT DISTINCT
						date_entered,
						guid,
						ttrss_entries.id,ttrss_entries.title,
						updated,
						label_cache,
						tag_cache,
						always_display_enclosures,
						site_url,
						note,
						num_comments,
						comments,
						int_id,
						unread,feed_id,marked,published,link,last_read,orig_feed_id,
						".SUBSTRING_FOR_DATE."(last_read,1,19) as last_read_noms,
						$vfeed_query_part
						$content_query_part
						".SUBSTRING_FOR_DATE."(updated,1,19) as updated_noms,
						author,score
					FROM
						$from_qpart
					WHERE
					$feed_check_qpart
					ttrss_user_entries.ref_id = ttrss_entries.id AND
					ttrss_user_entries.owner_uid = '$owner_uid' AND
					$search_query_part
					$filter_query_part
					$view_query_part
					$since_id_part
					$query_strategy_part ORDER BY $order_by
					$limit_query_part $offset_query_part";

				if ($_REQUEST["debug"]) print $query;

				$result = db_query($link, $query);

			} else {
				// browsing by tag

				$select_qpart = "SELECT DISTINCT " .
								"date_entered," .
								"guid," .
								"note," .
								"ttrss_entries.id as id," .
								"title," .
								"updated," .
								"unread," .
								"feed_id," .
								"orig_feed_id," .
								"marked," .
								"num_comments, " .
								"comments, " .
								"tag_cache," .
								"label_cache," .
								"link," .
								"last_read," .
								SUBSTRING_FOR_DATE . "(last_read,1,19) as last_read_noms," .
								$since_id_part .
								$vfeed_query_part .
								$content_query_part .
								SUBSTRING_FOR_DATE . "(updated,1,19) as updated_noms," .
								"score ";

				$feed_kind = "Tags";
				$all_tags = explode(",", $feed);
				if ($search_mode == 'any') {
					$tag_sql = "tag_name in (" . implode(", ", array_map("db_quote", $all_tags)) . ")";
					$from_qpart = " FROM ttrss_entries,ttrss_user_entries,ttrss_tags ";
					$where_qpart = " WHERE " .
								   "ref_id = ttrss_entries.id AND " .
								   "ttrss_user_entries.owner_uid = $owner_uid AND " .
								   "post_int_id = int_id AND $tag_sql AND " .
								   $view_query_part .
								   $search_query_part .
								   $query_strategy_part . " ORDER BY $order_by " .
								   $limit_query_part;

				} else {
					$i = 1;
					$sub_selects = array();
					$sub_ands = array();
					foreach ($all_tags as $term) {
						array_push($sub_selects, "(SELECT post_int_id from ttrss_tags WHERE tag_name = " . db_quote($term) . " AND owner_uid = $owner_uid) as A$i");
						$i++;
					}
					if ($i > 2) {
						$x = 1;
						$y = 2;
						do {
							array_push($sub_ands, "A$x.post_int_id = A$y.post_int_id");
							$x++;
							$y++;
						} while ($y < $i);
					}
					array_push($sub_ands, "A1.post_int_id = ttrss_user_entries.int_id and ttrss_user_entries.owner_uid = $owner_uid");
					array_push($sub_ands, "ttrss_user_entries.ref_id = ttrss_entries.id");
					$from_qpart = " FROM " . implode(", ", $sub_selects) . ", ttrss_user_entries, ttrss_entries";
					$where_qpart = " WHERE " . implode(" AND ", $sub_ands);
				}
				//				error_log("TAG SQL: " . $tag_sql);
				// $tag_sql = "tag_name = '$feed'";   DEFAULT way

				//				error_log("[". $select_qpart . "][" . $from_qpart . "][" .$where_qpart . "]");
				$result = db_query($link, $select_qpart . $from_qpart . $where_qpart);
			}

			return array($result, $feed_title, $feed_site_url, $last_error);

	}

	function sanitize($link, $str, $force_strip_tags = false, $owner = false, $site_url = false) {
		global $purifier;

		if (!$owner) $owner = $_SESSION["uid"];

		$res = trim($str); if (!$res) return '';

		// create global Purifier object if needed
		if (!$purifier) {
			require_once 'lib/htmlpurifier/library/HTMLPurifier.auto.php';

			$config = HTMLPurifier_Config::createDefault();

			$allowed = "p,a[href],i,em,b,strong,code,pre,blockquote,br,img[src|alt|title|align|hspace],ul,ol,li,h1,h2,h3,h4,s,object[classid|type|id|name|width|height|codebase],param[name|value],table,tr,td,span[class]";

			$config->set('HTML.SafeObject', true);
			@$config->set('HTML', 'Allowed', $allowed);
			$config->set('Output.FlashCompat', true);
			$config->set('Attr.EnableID', true);
			if (!defined('MOBILE_VERSION')) {
				@$config->set('Cache', 'SerializerPath', CACHE_DIR . "/htmlpurifier");
			} else {
				@$config->set('Cache', 'SerializerPath', "../" . CACHE_DIR . "/htmlpurifier");
			}

			$config->set('Filter.YouTube', true);

			$purifier = new HTMLPurifier($config);
		}

		$res = $purifier->purify($res);

		if (get_pref($link, "STRIP_IMAGES", $owner)) {
			$res = preg_replace('/<img[^>]+>/is', '', $res);
		}

		if (strpos($res, "href=") === false)
			$res = rewrite_urls($res);

		$charset_hack = '<head>
			<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
		</head>';

		$res = trim($res); if (!$res) return '';

		libxml_use_internal_errors(true);

		$doc = new DOMDocument();
		$doc->loadHTML($charset_hack . $res);
		$xpath = new DOMXPath($doc);

		$entries = $xpath->query('(//a[@href]|//img[@src])');
		$br_inserted = 0;

		foreach ($entries as $entry) {

			if ($site_url) {

				if ($entry->hasAttribute('href'))
					$entry->setAttribute('href',
						rewrite_relative_url($site_url, $entry->getAttribute('href')));

				if ($entry->hasAttribute('src'))
					if (preg_match('/^image.php\?i=[a-z0-9]+$/', $entry->getAttribute('src')) == 0)
						$entry->setAttribute('src',
							rewrite_relative_url($site_url, $entry->getAttribute('src')));
			}

			if (strtolower($entry->nodeName) == "a") {
				$entry->setAttribute("target", "_blank");
			}

			if (strtolower($entry->nodeName) == "img" && !$br_inserted) {
				$br = $doc->createElement("br");

				if ($entry->parentNode->nextSibling) {
					$entry->parentNode->insertBefore($br, $entry->nextSibling);
					$br_inserted = 1;
				}

			}
		}

		$node = $doc->getElementsByTagName('body')->item(0);

		return $doc->saveXML($node, LIBXML_NOEMPTYTAG);
	}

	/**
	 * Send by mail a digest of last articles.
	 *
	 * @param mixed $link The database connection.
	 * @param integer $limit The maximum number of articles by digest.
	 * @return boolean Return false if digests are not enabled.
	 */
	function send_headlines_digests($link, $debug = false) {

		require_once 'lib/phpmailer/class.phpmailer.php';

		$user_limit = 15; // amount of users to process (e.g. emails to send out)
		$limit = 1000; // maximum amount of headlines to include

		if ($debug) _debug("Sending digests, batch of max $user_limit users, headline limit = $limit");

		if (DB_TYPE == "pgsql") {
			$interval_query = "last_digest_sent < NOW() - INTERVAL '1 days'";
		} else if (DB_TYPE == "mysql") {
			$interval_query = "last_digest_sent < DATE_SUB(NOW(), INTERVAL 1 DAY)";
		}

		$result = db_query($link, "SELECT id,email FROM ttrss_users
				WHERE email != '' AND (last_digest_sent IS NULL OR $interval_query)");

		while ($line = db_fetch_assoc($result)) {

			if (get_pref($link, 'DIGEST_ENABLE', $line['id'], false)) {
				$preferred_ts = strtotime(get_pref($link, 'DIGEST_PREFERRED_TIME', $line['id'], '00:00'));

				// try to send digests within 2 hours of preferred time
				if ($preferred_ts && time() >= $preferred_ts &&
						time() - $preferred_ts <= 7200) {

					if ($debug) print "Sending digest for UID:" . $line['id'] . " - " . $line["email"] . " ... ";

					$do_catchup = get_pref($link, 'DIGEST_CATCHUP', $line['id'], false);

					global $tz_offset;

					// reset tz_offset global to prevent tz cache clash between users
					$tz_offset = -1;

					$tuple = prepare_headlines_digest($link, $line["id"], 1, $limit);
					$digest = $tuple[0];
					$headlines_count = $tuple[1];
					$affected_ids = $tuple[2];
					$digest_text = $tuple[3];

					if ($headlines_count > 0) {

						$mail = new PHPMailer();

						$mail->PluginDir = "lib/phpmailer/";
						$mail->SetLanguage("en", "lib/phpmailer/language/");

						$mail->CharSet = "UTF-8";

						$mail->From = SMTP_FROM_ADDRESS;
						$mail->FromName = SMTP_FROM_NAME;
						$mail->AddAddress($line["email"], $line["login"]);

						if (SMTP_HOST) {
							$mail->Host = SMTP_HOST;
							$mail->Mailer = "smtp";
							$mail->SMTPAuth = SMTP_LOGIN != '';
							$mail->Username = SMTP_LOGIN;
							$mail->Password = SMTP_PASSWORD;
						}

						$mail->IsHTML(true);
						$mail->Subject = DIGEST_SUBJECT;
						$mail->Body = $digest;
						$mail->AltBody = $digest_text;

						$rc = $mail->Send();

						if (!$rc && $debug) print "ERROR: " . $mail->ErrorInfo;

						if ($debug) print "RC=$rc\n";

						if ($rc && $do_catchup) {
							if ($debug) print "Marking affected articles as read...\n";
							catchupArticlesById($link, $affected_ids, 0, $line["id"]);
						}
					} else {
						if ($debug) print "No headlines\n";
					}

					db_query($link, "UPDATE ttrss_users SET last_digest_sent = NOW()
						WHERE id = " . $line["id"]);

				}
			}
		}

		if ($debug) _debug("All done.");

	}

	function prepare_headlines_digest($link, $user_id, $days = 1, $limit = 1000) {

		require_once "lib/MiniTemplator.class.php";

		$tpl = new MiniTemplator;
		$tpl_t = new MiniTemplator;

		$tpl->readTemplateFromFile("templates/digest_template_html.txt");
		$tpl_t->readTemplateFromFile("templates/digest_template.txt");

		$user_tz_string = get_pref($link, 'USER_TIMEZONE', $user_id);
		$local_ts = convert_timestamp(time(), 'UTC', $user_tz_string);

		$tpl->setVariable('CUR_DATE', date('Y/m/d', $local_ts));
		$tpl->setVariable('CUR_TIME', date('G:i', $local_ts));

		$tpl_t->setVariable('CUR_DATE', date('Y/m/d', $local_ts));
		$tpl_t->setVariable('CUR_TIME', date('G:i', $local_ts));

		$affected_ids = array();

		if (DB_TYPE == "pgsql") {
			$interval_query = "ttrss_entries.date_updated > NOW() - INTERVAL '$days days'";
		} else if (DB_TYPE == "mysql") {
			$interval_query = "ttrss_entries.date_updated > DATE_SUB(NOW(), INTERVAL $days DAY)";
		}

		$result = db_query($link, "SELECT ttrss_entries.title,
				ttrss_feeds.title AS feed_title,
				ttrss_feed_categories.title AS cat_title,
				date_updated,
				ttrss_user_entries.ref_id,
				link,
				score,
				content,
				".SUBSTRING_FOR_DATE."(last_updated,1,19) AS last_updated
			FROM
				ttrss_user_entries,ttrss_entries,ttrss_feeds
			LEFT JOIN
				ttrss_feed_categories ON (cat_id = ttrss_feed_categories.id)
			WHERE
				ref_id = ttrss_entries.id AND feed_id = ttrss_feeds.id
				AND include_in_digest = true
				AND $interval_query
				AND ttrss_user_entries.owner_uid = $user_id
				AND unread = true
				AND score >= 0
			ORDER BY ttrss_feed_categories.title, ttrss_feeds.title, score DESC, date_updated DESC
			LIMIT $limit");

		$cur_feed_title = "";

		$headlines_count = db_num_rows($result);

		$headlines = array();

		while ($line = db_fetch_assoc($result)) {
			array_push($headlines, $line);
		}

		for ($i = 0; $i < sizeof($headlines); $i++) {

			$line = $headlines[$i];

			array_push($affected_ids, $line["ref_id"]);

			$updated = make_local_datetime($link, $line['last_updated'], false,
				$user_id);

/*			if ($line["score"] != 0) {
				if ($line["score"] > 0) $line["score"] = '+' . $line["score"];

				$line["title"] .= " (".$line['score'].")";
			} */

			if (get_pref($link, 'ENABLE_FEED_CATS', $user_id)) {
				if (!$line['cat_title']) $line['cat_title'] = __("Uncategorized");

				$line['feed_title'] = $line['cat_title'] . " / " . $line['feed_title'];
			}

			$tpl->setVariable('FEED_TITLE', $line["feed_title"]);
			$tpl->setVariable('ARTICLE_TITLE', $line["title"]);
			$tpl->setVariable('ARTICLE_LINK', $line["link"]);
			$tpl->setVariable('ARTICLE_UPDATED', $updated);
			$tpl->setVariable('ARTICLE_EXCERPT',
				truncate_string(strip_tags($line["content"]), 300));
//			$tpl->setVariable('ARTICLE_CONTENT',
//				strip_tags($article_content));

			$tpl->addBlock('article');

			$tpl_t->setVariable('FEED_TITLE', $line["feed_title"]);
			$tpl_t->setVariable('ARTICLE_TITLE', $line["title"]);
			$tpl_t->setVariable('ARTICLE_LINK', $line["link"]);
			$tpl_t->setVariable('ARTICLE_UPDATED', $updated);
//			$tpl_t->setVariable('ARTICLE_EXCERPT',
//				truncate_string(strip_tags($line["excerpt"]), 100));

			$tpl_t->addBlock('article');

			if ($headlines[$i]['feed_title'] != $headlines[$i+1]['feed_title']) {
				$tpl->addBlock('feed');
				$tpl_t->addBlock('feed');
			}

		}

		$tpl->addBlock('digest');
		$tpl->generateOutputToString($tmp);

		$tpl_t->addBlock('digest');
		$tpl_t->generateOutputToString($tmp_t);

		return array($tmp, $headlines_count, $affected_ids, $tmp_t);
	}

	function check_for_update($link) {
		if (CHECK_FOR_NEW_VERSION && $_SESSION['access_level'] >= 10) {
			$version_url = "http://tt-rss.org/version.php?ver=" . VERSION;

			$version_data = @fetch_file_contents($version_url);

			if ($version_data) {
				$version_data = json_decode($version_data, true);
				if ($version_data && $version_data['version']) {

					if (version_compare(VERSION, $version_data['version']) == -1) {
						return $version_data;
					}
				}
			}
		}
		return false;
	}

	function markArticlesById($link, $ids, $cmode) {

		$tmp_ids = array();

		foreach ($ids as $id) {
			array_push($tmp_ids, "ref_id = '$id'");
		}

		$ids_qpart = join(" OR ", $tmp_ids);

		if ($cmode == 0) {
			db_query($link, "UPDATE ttrss_user_entries SET
			marked = false,last_read = NOW()
			WHERE ($ids_qpart) AND owner_uid = " . $_SESSION["uid"]);
		} else if ($cmode == 1) {
			db_query($link, "UPDATE ttrss_user_entries SET
			marked = true
			WHERE ($ids_qpart) AND owner_uid = " . $_SESSION["uid"]);
		} else {
			db_query($link, "UPDATE ttrss_user_entries SET
			marked = NOT marked,last_read = NOW()
			WHERE ($ids_qpart) AND owner_uid = " . $_SESSION["uid"]);
		}
	}

	function publishArticlesById($link, $ids, $cmode) {

		$tmp_ids = array();

		foreach ($ids as $id) {
			array_push($tmp_ids, "ref_id = '$id'");
		}

		$ids_qpart = join(" OR ", $tmp_ids);

		if ($cmode == 0) {
			db_query($link, "UPDATE ttrss_user_entries SET
			published = false,last_read = NOW()
			WHERE ($ids_qpart) AND owner_uid = " . $_SESSION["uid"]);
		} else if ($cmode == 1) {
			db_query($link, "UPDATE ttrss_user_entries SET
			published = true
			WHERE ($ids_qpart) AND owner_uid = " . $_SESSION["uid"]);
		} else {
			db_query($link, "UPDATE ttrss_user_entries SET
			published = NOT published,last_read = NOW()
			WHERE ($ids_qpart) AND owner_uid = " . $_SESSION["uid"]);
		}

		if (PUBSUBHUBBUB_HUB) {
			$rss_link = get_self_url_prefix() .
				"/public.php?op=rss&id=-2&key=" .
				get_feed_access_key($link, -2, false);

			$p = new Publisher(PUBSUBHUBBUB_HUB);

			$pubsub_result = $p->publish_update($rss_link);
		}
	}

	function catchupArticlesById($link, $ids, $cmode, $owner_uid = false) {

		if (!$owner_uid) $owner_uid = $_SESSION["uid"];
		if (count($ids) == 0) return;

		$tmp_ids = array();

		foreach ($ids as $id) {
			array_push($tmp_ids, "ref_id = '$id'");
		}

		$ids_qpart = join(" OR ", $tmp_ids);

		if ($cmode == 0) {
			db_query($link, "UPDATE ttrss_user_entries SET
			unread = false,last_read = NOW()
			WHERE ($ids_qpart) AND owner_uid = $owner_uid");
		} else if ($cmode == 1) {
			db_query($link, "UPDATE ttrss_user_entries SET
			unread = true
			WHERE ($ids_qpart) AND owner_uid = $owner_uid");
		} else {
			db_query($link, "UPDATE ttrss_user_entries SET
			unread = NOT unread,last_read = NOW()
			WHERE ($ids_qpart) AND owner_uid = $owner_uid");
		}

		/* update ccache */

		$result = db_query($link, "SELECT DISTINCT feed_id FROM ttrss_user_entries
			WHERE ($ids_qpart) AND owner_uid = $owner_uid");

		while ($line = db_fetch_assoc($result)) {
			ccache_update($link, $line["feed_id"], $owner_uid);
		}
	}

	function catchupArticleById($link, $id, $cmode) {

		if ($cmode == 0) {
			db_query($link, "UPDATE ttrss_user_entries SET
			unread = false,last_read = NOW()
			WHERE ref_id = '$id' AND owner_uid = " . $_SESSION["uid"]);
		} else if ($cmode == 1) {
			db_query($link, "UPDATE ttrss_user_entries SET
			unread = true
			WHERE ref_id = '$id' AND owner_uid = " . $_SESSION["uid"]);
		} else {
			db_query($link, "UPDATE ttrss_user_entries SET
			unread = NOT unread,last_read = NOW()
			WHERE ref_id = '$id' AND owner_uid = " . $_SESSION["uid"]);
		}

		$feed_id = getArticleFeed($link, $id);
		ccache_update($link, $feed_id, $_SESSION["uid"]);
	}

	function make_guid_from_title($title) {
		return preg_replace("/[ \"\',.:;]/", "-",
			mb_strtolower(strip_tags($title), 'utf-8'));
	}

	function get_article_tags($link, $id, $owner_uid = 0, $tag_cache = false) {

		global $memcache;

		$a_id = db_escape_string($id);

		if (!$owner_uid) $owner_uid = $_SESSION["uid"];

		$query = "SELECT DISTINCT tag_name,
			owner_uid as owner FROM
			ttrss_tags WHERE post_int_id = (SELECT int_id FROM ttrss_user_entries WHERE
			ref_id = '$a_id' AND owner_uid = '$owner_uid' LIMIT 1) ORDER BY tag_name";

		$obj_id = md5("TAGS:$owner_uid:$id");
		$tags = array();

		if ($memcache && $obj = $memcache->get($obj_id)) {
			$tags = $obj;
		} else {
			/* check cache first */

			if ($tag_cache === false) {
				$result = db_query($link, "SELECT tag_cache FROM ttrss_user_entries
					WHERE ref_id = '$id' AND owner_uid = " . $_SESSION["uid"]);

				$tag_cache = db_fetch_result($result, 0, "tag_cache");
			}

			if ($tag_cache) {
				$tags = explode(",", $tag_cache);
			} else {

				/* do it the hard way */

				$tmp_result = db_query($link, $query);

				while ($tmp_line = db_fetch_assoc($tmp_result)) {
					array_push($tags, $tmp_line["tag_name"]);
				}

				/* update the cache */

				$tags_str = db_escape_string(join(",", $tags));

				db_query($link, "UPDATE ttrss_user_entries
					SET tag_cache = '$tags_str' WHERE ref_id = '$id'
					AND owner_uid = " . $_SESSION["uid"]);
			}

			if ($memcache) $memcache->add($obj_id, $tags, 0, 3600);
		}

		return $tags;
	}

	function trim_array($array) {
		$tmp = $array;
		array_walk($tmp, 'trim');
		return $tmp;
	}

	function tag_is_valid($tag) {
		if ($tag == '') return false;
		if (preg_match("/^[0-9]*$/", $tag)) return false;
		if (mb_strlen($tag) > 250) return false;

		if (function_exists('iconv')) {
			$tag = iconv("utf-8", "utf-8", $tag);
		}

		if (!$tag) return false;

		return true;
	}

	function render_login_form($link, $mobile = 0) {
		switch ($mobile) {
		case 0:
			require_once "login_form.php";
			break;
		case 1:
			require_once "mobile/login_form.php";
			break;
		case 2:
			require_once "mobile/classic/login_form.php";
		}
	}

	// from http://developer.apple.com/internet/safari/faq.html
	function no_cache_incantation() {
		header("Expires: Mon, 22 Dec 1980 00:00:00 GMT"); // Happy birthday to me :)
		header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT"); // always modified
		header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0"); // HTTP/1.1
		header("Cache-Control: post-check=0, pre-check=0", false);
		header("Pragma: no-cache"); // HTTP/1.0
	}

	function format_warning($msg, $id = "") {
		global $link;
		return "<div class=\"warning\" id=\"$id\">
			<img src=\"".theme_image($link, "images/sign_excl.png")."\">$msg</div>";
	}

	function format_notice($msg, $id = "") {
		global $link;
		return "<div class=\"notice\" id=\"$id\">
			<img src=\"".theme_image($link, "images/sign_info.png")."\">$msg</div>";
	}

	function format_error($msg, $id = "") {
		global $link;
		return "<div class=\"error\" id=\"$id\">
			<img src=\"".theme_image($link, "images/sign_excl.png")."\">$msg</div>";
	}

	function print_notice($msg) {
		return print format_notice($msg);
	}

	function print_warning($msg) {
		return print format_warning($msg);
	}

	function print_error($msg) {
		return print format_error($msg);
	}


	function T_sprintf() {
		$args = func_get_args();
		return vsprintf(__(array_shift($args)), $args);
	}

	function format_inline_player($link, $url, $ctype) {

		$entry = "";

		if (strpos($ctype, "audio/") === 0) {

			if ($_SESSION["hasAudio"] && (strpos($ctype, "ogg") !== false ||
				strpos($_SERVER['HTTP_USER_AGENT'], "Chrome") !== false ||
				strpos($_SERVER['HTTP_USER_AGENT'], "Safari") !== false )) {

				$id = 'AUDIO-' . uniqid();

				$entry .= "<audio id=\"$id\"\">
					<source src=\"$url\"></source>
					</audio>";

				$entry .= "<span onclick=\"player(this)\"
					title=\"".__("Click to play")."\" status=\"0\"
					class=\"player\" audio-id=\"$id\">".__("Play")."</span>";

			} else {

				$entry .= "<object type=\"application/x-shockwave-flash\"
					data=\"lib/button/musicplayer.swf?song_url=$url\"
					width=\"17\" height=\"17\" style='float : left; margin-right : 5px;'>
					<param name=\"movie\"
						value=\"lib/button/musicplayer.swf?song_url=$url\" />
					</object>";
			}
		}

		$filename = substr($url, strrpos($url, "/")+1);

		$entry .= " <a target=\"_blank\" href=\"" . htmlspecialchars($url) . "\">" .
			$filename . " (" . $ctype . ")" . "</a>";

		return $entry;
	}

	function format_article($link, $id, $mark_as_read = true, $zoom_mode = false) {

		$rv = array();

		$rv['id'] = $id;

		/* we can figure out feed_id from article id anyway, why do we
		 * pass feed_id here? let's ignore the argument :( */

		$result = db_query($link, "SELECT feed_id FROM ttrss_user_entries
			WHERE ref_id = '$id'");

		$feed_id = (int) db_fetch_result($result, 0, "feed_id");

		$rv['feed_id'] = $feed_id;

		//if (!$zoom_mode) { print "<article id='$id'><![CDATA["; };

		$result = db_query($link, "SELECT rtl_content, always_display_enclosures FROM ttrss_feeds
			WHERE id = '$feed_id' AND owner_uid = " . $_SESSION["uid"]);

		if (db_num_rows($result) == 1) {
			$rtl_content = sql_bool_to_bool(db_fetch_result($result, 0, "rtl_content"));
			$always_display_enclosures = sql_bool_to_bool(db_fetch_result($result, 0, "always_display_enclosures"));
		} else {
			$rtl_content = false;
			$always_display_enclosures = false;
		}

		if ($rtl_content) {
			$rtl_tag = "dir=\"RTL\"";
			$rtl_class = "RTL";
		} else {
			$rtl_tag = "";
			$rtl_class = "";
		}

		if ($mark_as_read) {
			$result = db_query($link, "UPDATE ttrss_user_entries
				SET unread = false,last_read = NOW()
				WHERE ref_id = '$id' AND owner_uid = " . $_SESSION["uid"]);

			ccache_update($link, $feed_id, $_SESSION["uid"]);
		}

		$result = db_query($link, "SELECT title,link,content,feed_id,comments,int_id,
			".SUBSTRING_FOR_DATE."(updated,1,16) as updated,
			(SELECT icon_url FROM ttrss_feeds WHERE id = feed_id) as icon_url,
			(SELECT site_url FROM ttrss_feeds WHERE id = feed_id) as site_url,
			num_comments,
			tag_cache,
			author,
			orig_feed_id,
			note
			FROM ttrss_entries,ttrss_user_entries
			WHERE	id = '$id' AND ref_id = id AND owner_uid = " . $_SESSION["uid"]);

		if ($result) {

			$line = db_fetch_assoc($result);

			if ($line["icon_url"]) {
				$feed_icon = "<img src=\"" . $line["icon_url"] . "\">";
			} else {
				$feed_icon = "&nbsp;";
			}

			$feed_site_url = $line['site_url'];

			$num_comments = $line["num_comments"];
			$entry_comments = "";

			if ($num_comments > 0) {
				if ($line["comments"]) {
					$comments_url = $line["comments"];
				} else {
					$comments_url = $line["link"];
				}
				$entry_comments = "<a target='_blank' href=\"$comments_url\">$num_comments comments</a>";
			} else {
				if ($line["comments"] && $line["link"] != $line["comments"]) {
					$entry_comments = "<a target='_blank' href=\"".$line["comments"]."\">comments</a>";
				}
			}

			if ($zoom_mode) {
				header("Content-Type: text/html");
				$rv['content'] .= "<html><head>
						<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\"/>
						<title>Tiny Tiny RSS - ".$line["title"]."</title>
						<link rel=\"stylesheet\" type=\"text/css\" href=\"tt-rss.css\">
					</head><body>";
			}

			$rv['content'] .= "<div id=\"PTITLE-$id\" style=\"display : none\">" .
				truncate_string(strip_tags($line['title']), 15) . "</div>";

			$rv['content'] .= "<div class=\"postReply\" id=\"POST-$id\">";

			$rv['content'] .= "<div onclick=\"return postClicked(event, $id)\"
				class=\"postHeader\" id=\"POSTHDR-$id\">";

			$entry_author = $line["author"];

			if ($entry_author) {
				$entry_author = __(" - ") . $entry_author;
			}

			$parsed_updated = make_local_datetime($link, $line["updated"], true,
				false, true);

			$rv['content'] .= "<div class=\"postDate$rtl_class\">$parsed_updated</div>";

			if ($line["link"]) {
				$rv['content'] .= "<div clear='both'><a target='_blank'
					title=\"".htmlspecialchars($line['title'])."\"
					href=\"" .
					$line["link"] . "\">" .
					truncate_string($line["title"], 100) .
					"<span class='author'>$entry_author</span></a></div>";
			} else {
				$rv['content'] .= "<div clear='both'>" . $line["title"] . "$entry_author</div>";
			}

			$tag_cache = $line["tag_cache"];

			if (!$tag_cache)
				$tags = get_article_tags($link, $id);
			else
				$tags = explode(",", $tag_cache);

			$tags_str = format_tags_string($tags, $id);
			$tags_str_full = join(", ", $tags);

			if (!$tags_str_full) $tags_str_full = __("no tags");

			if (!$entry_comments) $entry_comments = "&nbsp;"; # placeholder

			$rv['content'] .= "<div style='float : right'>
				<img src='".theme_image($link, 'images/tag.png')."'
				class='tagsPic' alt='Tags' title='Tags'>&nbsp;";

			if (!$zoom_mode) {
				$rv['content'] .= "<span id=\"ATSTR-$id\">$tags_str</span>
					<a title=\"".__('Edit tags for this article')."\"
					href=\"#\" onclick=\"editArticleTags($id, $feed_id)\">(+)</a>";

				$rv['content'] .= "<div dojoType=\"dijit.Tooltip\"
					id=\"ATSTRTIP-$id\" connectId=\"ATSTR-$id\"
					position=\"below\">$tags_str_full</div>";

				$rv['content'] .= "<img src=\"".theme_image($link, 'images/art-zoom.png')."\"
						class='tagsPic' style=\"cursor : pointer\"
						onclick=\"postOpenInNewTab(event, $id)\"
						alt='Zoom' title='".__('Open article in new tab')."'>";

				$button_plugins = explode(",", ARTICLE_BUTTON_PLUGINS);

				foreach ($button_plugins as $p) {
					$pclass = trim("${p}_button");

					if (class_exists($pclass)) {
						$plugin = new $pclass($link);
						$rv['content'] .= $plugin->render($id, $line);
					}
				}

				$rv['content'] .= "<img src=\"".theme_image($link, 'images/digest_checkbox.png')."\"
						class='tagsPic' style=\"cursor : pointer\"
						onclick=\"closeArticlePanel($id)\"
						title='".__('Close article')."'>";

			} else {
				$tags_str = strip_tags($tags_str);
				$rv['content'] .= "<span id=\"ATSTR-$id\">$tags_str</span>";
			}
			$rv['content'] .= "</div>";
			$rv['content'] .= "<div clear='both'>$entry_comments</div>";

			if ($line["orig_feed_id"]) {

				$tmp_result = db_query($link, "SELECT * FROM ttrss_archived_feeds
					WHERE id = ".$line["orig_feed_id"]);

				if (db_num_rows($tmp_result) != 0) {

					$rv['content'] .= "<div clear='both'>";
					$rv['content'] .= __("Originally from:");

					$rv['content'] .= "&nbsp;";

					$tmp_line = db_fetch_assoc($tmp_result);

					$rv['content'] .= "<a target='_blank'
						href=' " . htmlspecialchars($tmp_line['site_url']) . "'>" .
						$tmp_line['title'] . "</a>";

					$rv['content'] .= "&nbsp;";

					$rv['content'] .= "<a target='_blank' href='" . htmlspecialchars($tmp_line['feed_url']) . "'>";
					$rv['content'] .= "<img title='".__('Feed URL')."'class='tinyFeedIcon' src='images/pub_set.png'></a>";

					$rv['content'] .= "</div>";
				}
			}

			$rv['content'] .= "</div>";

			$rv['content'] .= "<div id=\"POSTNOTE-$id\">";
				if ($line['note']) {
					$rv['content'] .= format_article_note($id, $line['note']);
				}
			$rv['content'] .= "</div>";

			$rv['content'] .= "<div class=\"postIcon\">" .
				"<a target=\"_blank\" title=\"".__("Visit the website")."\"$
				href=\"".htmlspecialchars($feed_site_url)."\">".
				$feed_icon . "</a></div>";

			$rv['content'] .= "<div class=\"postContent\">";

			$article_content = sanitize($link, $line["content"], false, false,
				$feed_site_url);

			$rv['content'] .= $article_content;

			$rv['content'] .= format_article_enclosures($link, $id,
				$always_display_enclosures, $article_content);

			$rv['content'] .= "</div>";

			$rv['content'] .= "</div>";

		}

		if ($zoom_mode) {
			$rv['content'] .= "
				<div style=\"text-align : center\">
				<button onclick=\"return window.close()\">".
					__("Close this window")."</button></div>";
			$rv['content'] .= "</body></html>";
		}

		return $rv;

	}

	function print_checkpoint($n, $s) {
		$ts = getmicrotime();
		echo sprintf("<!-- CP[$n] %.4f seconds -->", $ts - $s);
		return $ts;
	}

	function sanitize_tag($tag) {
		$tag = trim($tag);

		$tag = mb_strtolower($tag, 'utf-8');

		$tag = preg_replace('/[\'\"\+\>\<]/', "", $tag);

//		$tag = str_replace('"', "", $tag);
//		$tag = str_replace("+", " ", $tag);
		$tag = str_replace("technorati tag: ", "", $tag);

		return $tag;
	}

	function get_self_url_prefix() {
		return SELF_URL_PATH;
	}

	function opml_publish_url($link){

		$url_path = get_self_url_prefix();
		$url_path .= "/opml.php?op=publish&key=" .
			get_feed_access_key($link, 'OPML:Publish', false, $_SESSION["uid"]);

		return $url_path;
	}

	/**
	 * Purge a feed contents, marked articles excepted.
	 *
	 * @param mixed $link The database connection.
	 * @param integer $id The id of the feed to purge.
	 * @return void
	 */
	function clear_feed_articles($link, $id) {

		if ($id != 0) {
			$result = db_query($link, "DELETE FROM ttrss_user_entries
			WHERE feed_id = '$id' AND marked = false AND owner_uid = " . $_SESSION["uid"]);
		} else {
			$result = db_query($link, "DELETE FROM ttrss_user_entries
			WHERE feed_id IS NULL AND marked = false AND owner_uid = " . $_SESSION["uid"]);
		}

		$result = db_query($link, "DELETE FROM ttrss_entries WHERE
			(SELECT COUNT(int_id) FROM ttrss_user_entries WHERE ref_id = id) = 0");

		ccache_update($link, $id, $_SESSION['uid']);
	} // function clear_feed_articles

	/**
	 * Compute the Mozilla Firefox feed adding URL from server HOST and REQUEST_URI.
	 *
	 * @return string The Mozilla Firefox feed adding URL.
	 */
	function add_feed_url() {
		//$url_path = ($_SERVER['HTTPS'] != "on" ? 'http://' :  'https://') . $_SERVER["HTTP_HOST"] . parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

		$url_path = get_self_url_prefix() .
			"/backend.php?op=pref-feeds&quiet=1&method=add&feed_url=%s";
		return $url_path;
	} // function add_feed_url

	function encrypt_password($pass, $salt = '', $mode2 = false) {
		if ($salt && $mode2) {
			return "MODE2:" . hash('sha256', $salt . $pass);
		} else if ($salt) {
			return "SHA1X:" . sha1("$salt:$pass");
		} else {
			return "SHA1:" . sha1($pass);
		}
	} // function encrypt_password

	function sanitize_article_content($text) {
		# we don't support CDATA sections in articles, they break our own escaping
		$text = preg_replace("/\[\[CDATA/", "", $text);
		$text = preg_replace("/\]\]\>/", "", $text);
		return $text;
	}

	function load_filters($link, $feed, $owner_uid, $action_id = false) {
		$filters = array();

		global $memcache;

		$obj_id = md5("FILTER:$feed:$owner_uid:$action_id");

		if ($memcache && $obj = $memcache->get($obj_id)) {

			return $obj;

		} else {

			if ($action_id) $ftype_query_part = "action_id = '$action_id' AND";

			$result = db_query($link, "SELECT reg_exp,
				ttrss_filter_types.name AS name,
				ttrss_filter_actions.name AS action,
				inverse,
				action_param,
				filter_param
				FROM ttrss_filters
					LEFT JOIN ttrss_feeds ON (ttrss_feeds.id = '$feed'),
					ttrss_filter_types,ttrss_filter_actions
				WHERE
					enabled = true AND
					$ftype_query_part
					ttrss_filters.owner_uid = $owner_uid AND
					ttrss_filter_types.id = filter_type AND
					ttrss_filter_actions.id = action_id AND
					((cat_filter = true AND ttrss_feeds.cat_id = ttrss_filters.cat_id) OR
					(cat_filter = true AND ttrss_feeds.cat_id IS NULL AND
						ttrss_filters.cat_id IS NULL) OR
					(cat_filter = false AND (feed_id IS NULL OR feed_id = '$feed')))
				ORDER BY reg_exp");

			while ($line = db_fetch_assoc($result)) {

				if (!$filters[$line["name"]]) $filters[$line["name"]] = array();
					$filter["reg_exp"] = $line["reg_exp"];
					$filter["action"] = $line["action"];
					$filter["action_param"] = $line["action_param"];
					$filter["filter_param"] = $line["filter_param"];
					$filter["inverse"] = sql_bool_to_bool($line["inverse"]);

					array_push($filters[$line["name"]], $filter);
				}

			if ($memcache) $memcache->add($obj_id, $filters, 0, 3600*8);

			return $filters;
		}
	}

	function get_score_pic($score) {
		if ($score > 100) {
			return "score_high.png";
		} else if ($score > 0) {
			return "score_half_high.png";
		} else if ($score < -100) {
			return "score_low.png";
		} else if ($score < 0) {
			return "score_half_low.png";
		} else {
			return "score_neutral.png";
		}
	}

	function feed_has_icon($id) {
		return is_file(ICONS_DIR . "/$id.ico") && filesize(ICONS_DIR . "/$id.ico") > 0;
	}

	function init_connection($link) {
		if ($link) {

			if (DB_TYPE == "pgsql") {
				pg_query($link, "set client_encoding = 'UTF-8'");
				pg_set_client_encoding("UNICODE");
				pg_query($link, "set datestyle = 'ISO, european'");
				pg_query($link, "set TIME ZONE 0");
			} else {
				db_query($link, "SET time_zone = '+0:0'");

				if (defined('MYSQL_CHARSET') && MYSQL_CHARSET) {
					db_query($link, "SET NAMES " . MYSQL_CHARSET);
				}
			}
			return true;
		} else {
			print "Unable to connect to database:" . db_last_error();
			return false;
		}
	}

	/* function ccache_zero($link, $feed_id, $owner_uid) {
		db_query($link, "UPDATE ttrss_counters_cache SET
			value = 0, updated = NOW() WHERE
			feed_id = '$feed_id' AND owner_uid = '$owner_uid'");
	} */

	function ccache_zero_all($link, $owner_uid) {
		db_query($link, "UPDATE ttrss_counters_cache SET
			value = 0 WHERE owner_uid = '$owner_uid'");

		db_query($link, "UPDATE ttrss_cat_counters_cache SET
			value = 0 WHERE owner_uid = '$owner_uid'");
	}

	function ccache_remove($link, $feed_id, $owner_uid, $is_cat = false) {

		if (!$is_cat) {
			$table = "ttrss_counters_cache";
		} else {
			$table = "ttrss_cat_counters_cache";
		}

		db_query($link, "DELETE FROM $table WHERE
			feed_id = '$feed_id' AND owner_uid = '$owner_uid'");

	}

	function ccache_update_all($link, $owner_uid) {

		if (get_pref($link, 'ENABLE_FEED_CATS', $owner_uid)) {

			$result = db_query($link, "SELECT feed_id FROM ttrss_cat_counters_cache
				WHERE feed_id > 0 AND owner_uid = '$owner_uid'");

			while ($line = db_fetch_assoc($result)) {
				ccache_update($link, $line["feed_id"], $owner_uid, true);
			}

			/* We have to manually include category 0 */

			ccache_update($link, 0, $owner_uid, true);

		} else {
			$result = db_query($link, "SELECT feed_id FROM ttrss_counters_cache
				WHERE feed_id > 0 AND owner_uid = '$owner_uid'");

			while ($line = db_fetch_assoc($result)) {
				print ccache_update($link, $line["feed_id"], $owner_uid);

			}

		}
	}

	function ccache_find($link, $feed_id, $owner_uid, $is_cat = false,
		$no_update = false) {

		if (!is_numeric($feed_id)) return;

		if (!$is_cat) {
			$table = "ttrss_counters_cache";
			if ($feed_id > 0) {
				$tmp_result = db_query($link, "SELECT owner_uid FROM ttrss_feeds
					WHERE id = '$feed_id'");
				$owner_uid = db_fetch_result($tmp_result, 0, "owner_uid");
			}
		} else {
			$table = "ttrss_cat_counters_cache";
		}

		if (DB_TYPE == "pgsql") {
			$date_qpart = "updated > NOW() - INTERVAL '15 minutes'";
		} else if (DB_TYPE == "mysql") {
			$date_qpart = "updated > DATE_SUB(NOW(), INTERVAL 15 MINUTE)";
		}

		$result = db_query($link, "SELECT value FROM $table
			WHERE owner_uid = '$owner_uid' AND feed_id = '$feed_id'
			LIMIT 1");

		if (db_num_rows($result) == 1) {
			return db_fetch_result($result, 0, "value");
		} else {
			if ($no_update) {
				return -1;
			} else {
				return ccache_update($link, $feed_id, $owner_uid, $is_cat);
			}
		}

	}

	function ccache_update($link, $feed_id, $owner_uid, $is_cat = false,
		$update_pcat = true) {

		if (!is_numeric($feed_id)) return;

		if (!$is_cat && $feed_id > 0) {
			$tmp_result = db_query($link, "SELECT owner_uid FROM ttrss_feeds
				WHERE id = '$feed_id'");
			$owner_uid = db_fetch_result($tmp_result, 0, "owner_uid");
		}

		$prev_unread = ccache_find($link, $feed_id, $owner_uid, $is_cat, true);

		/* When updating a label, all we need to do is recalculate feed counters
		 * because labels are not cached */

		if ($feed_id < 0) {
			ccache_update_all($link, $owner_uid);
			return;
		}

		if (!$is_cat) {
			$table = "ttrss_counters_cache";
		} else {
			$table = "ttrss_cat_counters_cache";
		}

		if ($is_cat && $feed_id >= 0) {
			if ($feed_id != 0) {
				$cat_qpart = "cat_id = '$feed_id'";
			} else {
				$cat_qpart = "cat_id IS NULL";
			}

			/* Recalculate counters for child feeds */

			$result = db_query($link, "SELECT id FROM ttrss_feeds
						WHERE owner_uid = '$owner_uid' AND $cat_qpart");

			while ($line = db_fetch_assoc($result)) {
				ccache_update($link, $line["id"], $owner_uid, false, false);
			}

			$result = db_query($link, "SELECT SUM(value) AS sv
				FROM ttrss_counters_cache, ttrss_feeds
				WHERE id = feed_id AND $cat_qpart AND
				ttrss_feeds.owner_uid = '$owner_uid'");

			$unread = (int) db_fetch_result($result, 0, "sv");

		} else {
			$unread = (int) getFeedArticles($link, $feed_id, $is_cat, true, $owner_uid);
		}

		db_query($link, "BEGIN");

		$result = db_query($link, "SELECT feed_id FROM $table
			WHERE owner_uid = '$owner_uid' AND feed_id = '$feed_id' LIMIT 1");

		if (db_num_rows($result) == 1) {
			db_query($link, "UPDATE $table SET
				value = '$unread', updated = NOW() WHERE
				feed_id = '$feed_id' AND owner_uid = '$owner_uid'");

		} else {
			db_query($link, "INSERT INTO $table
				(feed_id, value, owner_uid, updated)
				VALUES
				($feed_id, $unread, $owner_uid, NOW())");
		}

		db_query($link, "COMMIT");

		if ($feed_id > 0 && $prev_unread != $unread) {

			if (!$is_cat) {

				/* Update parent category */

				if ($update_pcat) {

					$result = db_query($link, "SELECT cat_id FROM ttrss_feeds
						WHERE owner_uid = '$owner_uid' AND id = '$feed_id'");

					$cat_id = (int) db_fetch_result($result, 0, "cat_id");

					ccache_update($link, $cat_id, $owner_uid, true);

				}
			}
		} else if ($feed_id < 0) {
			ccache_update_all($link, $owner_uid);
		}

		return $unread;
	}

	/* function ccache_cleanup($link, $owner_uid) {

		if (DB_TYPE == "pgsql") {
			db_query($link, "DELETE FROM ttrss_counters_cache AS c1 WHERE
				(SELECT count(*) FROM ttrss_counters_cache AS c2
					WHERE c1.feed_id = c2.feed_id AND c2.owner_uid = c1.owner_uid) > 1
					AND owner_uid = '$owner_uid'");

			db_query($link, "DELETE FROM ttrss_cat_counters_cache AS c1 WHERE
				(SELECT count(*) FROM ttrss_cat_counters_cache AS c2
					WHERE c1.feed_id = c2.feed_id AND c2.owner_uid = c1.owner_uid) > 1
					AND owner_uid = '$owner_uid'");
		} else {
			db_query($link, "DELETE c1 FROM
					ttrss_counters_cache AS c1,
					ttrss_counters_cache AS c2
				WHERE
					c1.owner_uid = '$owner_uid' AND
					c1.owner_uid = c2.owner_uid AND
					c1.feed_id = c2.feed_id");

			db_query($link, "DELETE c1 FROM
					ttrss_cat_counters_cache AS c1,
					ttrss_cat_counters_cache AS c2
				WHERE
					c1.owner_uid = '$owner_uid' AND
					c1.owner_uid = c2.owner_uid AND
					c1.feed_id = c2.feed_id");

		}
	} */

	function label_find_id($link, $label, $owner_uid) {
		$result = db_query($link,
			"SELECT id FROM ttrss_labels2 WHERE caption = '$label'
				AND owner_uid = '$owner_uid' LIMIT 1");

		if (db_num_rows($result) == 1) {
			return db_fetch_result($result, 0, "id");
		} else {
			return 0;
		}
	}

	function get_article_labels($link, $id) {
		global $memcache;

		$obj_id = md5("LABELS:$id:" . $_SESSION["uid"]);

		$rv = array();

		if ($memcache && $obj = $memcache->get($obj_id)) {
			return $obj;
		} else {

			$result = db_query($link, "SELECT label_cache FROM
				ttrss_user_entries WHERE ref_id = '$id' AND owner_uid = " .
				$_SESSION["uid"]);

			$label_cache = db_fetch_result($result, 0, "label_cache");

			if ($label_cache) {

				$label_cache = json_decode($label_cache, true);

				if ($label_cache["no-labels"] == 1)
					return $rv;
				else
					return $label_cache;
			}

			$result = db_query($link,
				"SELECT DISTINCT label_id,caption,fg_color,bg_color
					FROM ttrss_labels2, ttrss_user_labels2
				WHERE id = label_id
					AND article_id = '$id'
					AND owner_uid = ".$_SESSION["uid"] . "
				ORDER BY caption");

			while ($line = db_fetch_assoc($result)) {
				$rk = array($line["label_id"], $line["caption"], $line["fg_color"],
					$line["bg_color"]);
				array_push($rv, $rk);
			}
			if ($memcache) $memcache->add($obj_id, $rv, 0, 3600);

			if (count($rv) > 0)
				label_update_cache($link, $id, $rv);
			else
				label_update_cache($link, $id, array("no-labels" => 1));
		}

		return $rv;
	}


	function label_find_caption($link, $label, $owner_uid) {
		$result = db_query($link,
			"SELECT caption FROM ttrss_labels2 WHERE id = '$label'
				AND owner_uid = '$owner_uid' LIMIT 1");

		if (db_num_rows($result) == 1) {
			return db_fetch_result($result, 0, "caption");
		} else {
			return "";
		}
	}

	function label_update_cache($link, $id, $labels = false, $force = false) {

		if ($force)
			label_clear_cache($link, $id);

		if (!$labels)
			$labels = get_article_labels($link, $id);

		$labels = db_escape_string(json_encode($labels));

		db_query($link, "UPDATE ttrss_user_entries SET
			label_cache = '$labels' WHERE ref_id = '$id'");

	}

	function label_clear_cache($link, $id) {

		db_query($link, "UPDATE ttrss_user_entries SET
			label_cache = '' WHERE ref_id = '$id'");

	}

	function label_remove_article($link, $id, $label, $owner_uid) {

		$label_id = label_find_id($link, $label, $owner_uid);

		if (!$label_id) return;

		$result = db_query($link,
			"DELETE FROM ttrss_user_labels2
			WHERE
				label_id = '$label_id' AND
				article_id = '$id'");

		label_clear_cache($link, $id);
	}

	function label_add_article($link, $id, $label, $owner_uid) {

		global $memcache;

		if ($memcache) {
			$obj_id = md5("LABELS:$id:$owner_uid");
			$memcache->delete($obj_id);
		}

		$label_id = label_find_id($link, $label, $owner_uid);

		if (!$label_id) return;

		$result = db_query($link,
			"SELECT
				article_id FROM ttrss_labels2, ttrss_user_labels2
			WHERE
				label_id = id AND
				label_id = '$label_id' AND
				article_id = '$id' AND owner_uid = '$owner_uid'
			LIMIT 1");

		if (db_num_rows($result) == 0) {
			db_query($link, "INSERT INTO ttrss_user_labels2
				(label_id, article_id) VALUES ('$label_id', '$id')");
		}

		label_clear_cache($link, $id);

	}

	function label_remove($link, $id, $owner_uid) {
		global $memcache;

		if (!$owner_uid) $owner_uid = $_SESSION["uid"];

		if ($memcache) {
			$obj_id = md5("LABELS:$id:$owner_uid");
			$memcache->delete($obj_id);
		}

		db_query($link, "BEGIN");

		$result = db_query($link, "SELECT caption FROM ttrss_labels2
			WHERE id = '$id'");

		$caption = db_fetch_result($result, 0, "caption");

		$result = db_query($link, "DELETE FROM ttrss_labels2 WHERE id = '$id'
			AND owner_uid = " . $owner_uid);

		if (db_affected_rows($link, $result) != 0 && $caption) {

			/* Remove access key for the label */

			$ext_id = -11 - $id;

			db_query($link, "DELETE FROM ttrss_access_keys WHERE
				feed_id = '$ext_id' AND owner_uid = $owner_uid");

			/* Disable filters that reference label being removed */

			db_query($link, "UPDATE ttrss_filters SET
				enabled = false WHERE action_param = '$caption'
					AND action_id = 7
					AND owner_uid = " . $owner_uid);

			/* Remove cached data */

			db_query($link, "UPDATE ttrss_user_entries SET label_cache = ''
				WHERE label_cache LIKE '%$caption%' AND owner_uid = " . $owner_uid);

		}

		db_query($link, "COMMIT");
	}

	function label_create($link, $caption, $fg_color = '', $bg_color = '', $owner_uid) {

		if (!$owner_uid) $owner_uid = $_SESSION['uid'];

		db_query($link, "BEGIN");

		$result = false;

		$result = db_query($link, "SELECT id FROM ttrss_labels2
			WHERE caption = '$caption' AND owner_uid = $owner_uid");

		if (db_num_rows($result) == 0) {
			$result = db_query($link,
				"INSERT INTO ttrss_labels2 (caption,owner_uid,fg_color,bg_color)
					VALUES ('$caption', '$owner_uid', '$fg_color', '$bg_color')");

			$result = db_affected_rows($link, $result) != 0;
		}

		db_query($link, "COMMIT");

		return $result;
	}

	function format_tags_string($tags, $id) {

		$tags_str = "";
		$tags_nolinks_str = "";

		$num_tags = 0;

		$tag_limit = 6;

		$formatted_tags = array();

		foreach ($tags as $tag) {
			$num_tags++;
			$tag_escaped = str_replace("'", "\\'", $tag);

			if (mb_strlen($tag) > 30) {
				$tag = truncate_string($tag, 30);
			}

			$tag_str = "<a href=\"javascript:viewfeed('$tag_escaped')\">$tag</a>";

			array_push($formatted_tags, $tag_str);

			$tmp_tags_str = implode(", ", $formatted_tags);

			if ($num_tags == $tag_limit || mb_strlen($tmp_tags_str) > 150) {
				break;
			}
		}

		$tags_str = implode(", ", $formatted_tags);

		if ($num_tags < count($tags)) {
			$tags_str .= ", &hellip;";
		}

		if ($num_tags == 0) {
			$tags_str = __("no tags");
		}

		return $tags_str;

	}

	function format_article_labels($labels, $id) {

		$labels_str = "";

		foreach ($labels as $l) {
			$labels_str .= sprintf("<span class='hlLabelRef'
				style='color : %s; background-color : %s'>%s</span>",
					$l[2], $l[3], $l[1]);
			}

		return $labels_str;

	}

	function format_article_note($id, $note) {

		$str = "<div class='articleNote'	onclick=\"editArticleNote($id)\">
			<div class='noteEdit' onclick=\"editArticleNote($id)\">".
			__('(edit note)')."</div>$note</div>";

		return $str;
	}

	function toggle_collapse_cat($link, $cat_id, $mode) {
		if ($cat_id > 0) {
			$mode = bool_to_sql_bool($mode);

			db_query($link, "UPDATE ttrss_feed_categories SET
				collapsed = $mode WHERE id = '$cat_id' AND owner_uid = " .
				$_SESSION["uid"]);
		} else {
			$pref_name = '';

			switch ($cat_id) {
			case -1:
				$pref_name = '_COLLAPSED_SPECIAL';
				break;
			case -2:
				$pref_name = '_COLLAPSED_LABELS';
				break;
			case 0:
				$pref_name = '_COLLAPSED_UNCAT';
				break;
			}

			if ($pref_name) {
				if ($mode) {
					set_pref($link, $pref_name, 'true');
				} else {
					set_pref($link, $pref_name, 'false');
				}
			}
		}
	}

	function remove_feed($link, $id, $owner_uid) {

		if ($id > 0) {

			/* save starred articles in Archived feed */

			db_query($link, "BEGIN");

			/* prepare feed if necessary */

			$result = db_query($link, "SELECT id FROM ttrss_archived_feeds
				WHERE id = '$id'");

			if (db_num_rows($result) == 0) {
				db_query($link, "INSERT INTO ttrss_archived_feeds
					(id, owner_uid, title, feed_url, site_url)
				SELECT id, owner_uid, title, feed_url, site_url from ttrss_feeds
			  	WHERE id = '$id'");
			}

			db_query($link, "UPDATE ttrss_user_entries SET feed_id = NULL,
				orig_feed_id = '$id' WHERE feed_id = '$id' AND
					marked = true AND owner_uid = $owner_uid");

			/* Remove access key for the feed */

			db_query($link, "DELETE FROM ttrss_access_keys WHERE
				feed_id = '$id' AND owner_uid = $owner_uid");

			/* remove the feed */

			db_query($link, "DELETE FROM ttrss_feeds
					WHERE id = '$id' AND owner_uid = $owner_uid");

			db_query($link, "COMMIT");

			if (file_exists(ICONS_DIR . "/$id.ico")) {
				unlink(ICONS_DIR . "/$id.ico");
			}

			ccache_remove($link, $id, $owner_uid);

		} else {
			label_remove($link, -11-$id, $owner_uid);
			ccache_remove($link, -11-$id, $owner_uid);
		}
	}

	function add_feed_category($link, $feed_cat) {

		if (!$feed_cat) return false;

		db_query($link, "BEGIN");

		$result = db_query($link,
			"SELECT id FROM ttrss_feed_categories
			WHERE title = '$feed_cat' AND owner_uid = ".$_SESSION["uid"]);

		if (db_num_rows($result) == 0) {

			$result = db_query($link,
				"INSERT INTO ttrss_feed_categories (owner_uid,title)
				VALUES ('".$_SESSION["uid"]."', '$feed_cat')");

			db_query($link, "COMMIT");

			return true;
		}

		return false;
	}

	function remove_feed_category($link, $id, $owner_uid) {

		db_query($link, "DELETE FROM ttrss_feed_categories
			WHERE id = '$id' AND owner_uid = $owner_uid");

		ccache_remove($link, $id, $owner_uid, true);
	}

	function archive_article($link, $id, $owner_uid) {
		db_query($link, "BEGIN");

		$result = db_query($link, "SELECT feed_id FROM ttrss_user_entries
			WHERE ref_id = '$id' AND owner_uid = $owner_uid");

		if (db_num_rows($result) != 0) {

			/* prepare the archived table */

			$feed_id = (int) db_fetch_result($result, 0, "feed_id");

			if ($feed_id) {
				$result = db_query($link, "SELECT id FROM ttrss_archived_feeds
					WHERE id = '$feed_id'");

				if (db_num_rows($result) == 0) {
					db_query($link, "INSERT INTO ttrss_archived_feeds
						(id, owner_uid, title, feed_url, site_url)
					SELECT id, owner_uid, title, feed_url, site_url from ttrss_feeds
				  	WHERE id = '$feed_id'");
				}

				db_query($link, "UPDATE ttrss_user_entries
					SET orig_feed_id = feed_id, feed_id = NULL
					WHERE ref_id = '$id' AND owner_uid = " . $_SESSION["uid"]);
			}
		}

		db_query($link, "COMMIT");
	}

	function getArticleFeed($link, $id) {
		$result = db_query($link, "SELECT feed_id FROM ttrss_user_entries
			WHERE ref_id = '$id' AND owner_uid = " . $_SESSION["uid"]);

		if (db_num_rows($result) != 0) {
			return db_fetch_result($result, 0, "feed_id");
		} else {
			return 0;
		}
	}

	/**
	 * Fixes incomplete URLs by prepending "http://".
	 * Also replaces feed:// with http://, and
	 * prepends a trailing slash if the url is a domain name only.
	 *
	 * @param string $url Possibly incomplete URL
	 *
	 * @return string Fixed URL.
	 */
	function fix_url($url) {
		if (strpos($url, '://') === false) {
			$url = 'http://' . $url;
		} else if (substr($url, 0, 5) == 'feed:') {
			$url = 'http:' . substr($url, 5);
		}

		//prepend slash if the URL has no slash in it
		// "http://www.example" -> "http://www.example/"
		if (strpos($url, '/', strpos($url, ':') + 3) === false) {
			$url .= '/';
		}

		if ($url != "http:///")
			return $url;
		else
			return '';
	}

	function validate_feed_url($url) {
		$parts = parse_url($url);

		return ($parts['scheme'] == 'http' || $parts['scheme'] == 'feed' || $parts['scheme'] == 'https');

	}

	function get_article_enclosures($link, $id) {

		global $memcache;

		$query = "SELECT * FROM ttrss_enclosures
			WHERE post_id = '$id' AND content_url != ''";

		$obj_id = md5("ENCLOSURES:$id");

		$rv = array();

		if ($memcache && $obj = $memcache->get($obj_id)) {
			$rv = $obj;
		} else {
			$result = db_query($link, $query);

			if (db_num_rows($result) > 0) {
				while ($line = db_fetch_assoc($result)) {
					array_push($rv, $line);
				}
				if ($memcache) $memcache->add($obj_id, $rv, 0, 3600);
			}
		}

		return $rv;
	}

	function api_get_feeds($link, $cat_id, $unread_only, $limit, $offset) {

			$feeds = array();

			/* Labels */

			if ($cat_id == -4 || $cat_id == -2) {
				$counters = getLabelCounters($link, true);

				foreach (array_values($counters) as $cv) {

					$unread = $cv["counter"];

					if ($unread || !$unread_only) {

						$row = array(
								"id" => $cv["id"],
								"title" => $cv["description"],
								"unread" => $cv["counter"],
								"cat_id" => -2,
							);

						array_push($feeds, $row);
					}
				}
			}

			/* Virtual feeds */

			if ($cat_id == -4 || $cat_id == -1) {
				foreach (array(-1, -2, -3, -4, 0) as $i) {
					$unread = getFeedUnread($link, $i);

					if ($unread || !$unread_only) {
						$title = getFeedTitle($link, $i);

						$row = array(
								"id" => $i,
								"title" => $title,
								"unread" => $unread,
								"cat_id" => -1,
							);
						array_push($feeds, $row);
					}

				}
			}

			/* Real feeds */

			if ($limit) {
				$limit_qpart = "LIMIT $limit OFFSET $offset";
			} else {
				$limit_qpart = "";
			}

			if ($cat_id == -4 || $cat_id == -3) {
				$result = db_query($link, "SELECT
					id, feed_url, cat_id, title, order_id, ".
						SUBSTRING_FOR_DATE."(last_updated,1,19) AS last_updated
						FROM ttrss_feeds WHERE owner_uid = " . $_SESSION["uid"] .
						" ORDER BY cat_id, title " . $limit_qpart);
			} else {

				if ($cat_id)
					$cat_qpart = "cat_id = '$cat_id'";
				else
					$cat_qpart = "cat_id IS NULL";

				$result = db_query($link, "SELECT
					id, feed_url, cat_id, title, order_id, ".
						SUBSTRING_FOR_DATE."(last_updated,1,19) AS last_updated
						FROM ttrss_feeds WHERE
						$cat_qpart AND owner_uid = " . $_SESSION["uid"] .
						" ORDER BY cat_id, title " . $limit_qpart);
			}

			while ($line = db_fetch_assoc($result)) {

				$unread = getFeedUnread($link, $line["id"]);

				$has_icon = feed_has_icon($line['id']);

				if ($unread || !$unread_only) {

					$row = array(
							"feed_url" => $line["feed_url"],
							"title" => $line["title"],
							"id" => (int)$line["id"],
							"unread" => (int)$unread,
							"has_icon" => $has_icon,
							"cat_id" => (int)$line["cat_id"],
							"last_updated" => strtotime($line["last_updated"]),
							"order_id" => (int) $line["order_id"],
						);

					array_push($feeds, $row);
				}
			}

		return $feeds;
	}

	function api_get_headlines($link, $feed_id, $limit, $offset,
				$filter, $is_cat, $show_excerpt, $show_content, $view_mode, $order,
				$include_attachments, $since_id,
				$search = "", $search_mode = "", $match_on = "") {

			$qfh_ret = queryFeedHeadlines($link, $feed_id, $limit,
				$view_mode, $is_cat, $search, $search_mode, $match_on,
				$order, $offset, 0, false, $since_id);

			$result = $qfh_ret[0];
			$feed_title = $qfh_ret[1];

			$headlines = array();

			while ($line = db_fetch_assoc($result)) {
				$is_updated = ($line["last_read"] == "" &&
					($line["unread"] != "t" && $line["unread"] != "1"));

				$tags = explode(",", $line["tag_cache"]);
				$labels = json_decode($line["label_cache"], true);

				//if (!$tags) $tags = get_article_tags($link, $line["id"]);
				//if (!$labels) $labels = get_article_labels($link, $line["id"]);

				$headline_row = array(
						"id" => (int)$line["id"],
						"unread" => sql_bool_to_bool($line["unread"]),
						"marked" => sql_bool_to_bool($line["marked"]),
						"published" => sql_bool_to_bool($line["published"]),
						"updated" => strtotime($line["updated"]),
						"is_updated" => $is_updated,
						"title" => $line["title"],
						"link" => $line["link"],
						"feed_id" => $line["feed_id"],
						"tags" => $tags,
					);

					if ($include_attachments)
						$headline_row['attachments'] = get_article_enclosures($link,
							$line['id']);

				if ($show_excerpt) {
					$excerpt = truncate_string(strip_tags($line["content_preview"]), 100);
					$headline_row["excerpt"] = $excerpt;
				}

				if ($show_content) {
					$headline_row["content"] = $line["content_preview"];
				}

				// unify label output to ease parsing
				if ($labels["no-labels"] == 1) $labels = array();

				$headline_row["labels"] = $labels;

				$headline_row["feed_title"] = $line["feed_title"];

				array_push($headlines, $headline_row);
			}

			return $headlines;
	}

	function generate_error_feed($link, $error) {
		$reply = array();

		$reply['headlines']['id'] = -6;
		$reply['headlines']['is_cat'] = false;

		$reply['headlines']['toolbar'] = '';
		$reply['headlines']['content'] = "<div class='whiteBox'>". $error . "</div>";

		$reply['headlines-info'] = array("count" => 0,
			"vgroup_last_feed" => '',
			"unread" => 0,
			"disable_cache" => true);

		return $reply;
	}


	function generate_dashboard_feed($link) {
		$reply = array();

		$reply['headlines']['id'] = -5;
		$reply['headlines']['is_cat'] = false;

		$reply['headlines']['toolbar'] = '';
		$reply['headlines']['content'] = "<div class='whiteBox'>".__('No feed selected.');

		$reply['headlines']['content'] .= "<p class=\"small\"><span class=\"insensitive\">";

		$result = db_query($link, "SELECT ".SUBSTRING_FOR_DATE."(MAX(last_updated), 1, 19) AS last_updated FROM ttrss_feeds
			WHERE owner_uid = " . $_SESSION['uid']);

		$last_updated = db_fetch_result($result, 0, "last_updated");
		$last_updated = make_local_datetime($link, $last_updated, false);

		$reply['headlines']['content'] .= sprintf(__("Feeds last updated at %s"), $last_updated);

		$result = db_query($link, "SELECT COUNT(id) AS num_errors
			FROM ttrss_feeds WHERE last_error != '' AND owner_uid = ".$_SESSION["uid"]);

		$num_errors = db_fetch_result($result, 0, "num_errors");

		if ($num_errors > 0) {
			$reply['headlines']['content'] .= "<br/>";
			$reply['headlines']['content'] .= "<a class=\"insensitive\" href=\"#\" onclick=\"showFeedsWithErrors()\">".
				__('Some feeds have update errors (click for details)')."</a>";
		}
		$reply['headlines']['content'] .= "</span></p>";

		$reply['headlines-info'] = array("count" => 0,
			"vgroup_last_feed" => '',
			"unread" => 0,
			"disable_cache" => true);

		return $reply;
	}

	function save_email_address($link, $email) {
		// FIXME: implement persistent storage of emails

		if (!$_SESSION['stored_emails'])
			$_SESSION['stored_emails'] = array();

		if (!in_array($email, $_SESSION['stored_emails']))
			array_push($_SESSION['stored_emails'], $email);
	}

	function update_feed_access_key($link, $feed_id, $is_cat, $owner_uid = false) {
		if (!$owner_uid) $owner_uid = $_SESSION["uid"];

		$sql_is_cat = bool_to_sql_bool($is_cat);

		$result = db_query($link, "SELECT access_key FROM ttrss_access_keys
			WHERE feed_id = '$feed_id'	AND is_cat = $sql_is_cat
			AND owner_uid = " . $owner_uid);

		if (db_num_rows($result) == 1) {
			$key = db_escape_string(sha1(uniqid(rand(), true)));

			db_query($link, "UPDATE ttrss_access_keys SET access_key = '$key'
				WHERE feed_id = '$feed_id' AND is_cat = $sql_is_cat
				AND owner_uid = " . $owner_uid);

			return $key;

		} else {
			return get_feed_access_key($link, $feed_id, $is_cat, $owner_uid);
		}
	}

	function get_feed_access_key($link, $feed_id, $is_cat, $owner_uid = false) {

		if (!$owner_uid) $owner_uid = $_SESSION["uid"];

		$sql_is_cat = bool_to_sql_bool($is_cat);

		$result = db_query($link, "SELECT access_key FROM ttrss_access_keys
			WHERE feed_id = '$feed_id'	AND is_cat = $sql_is_cat
			AND owner_uid = " . $owner_uid);

		if (db_num_rows($result) == 1) {
			return db_fetch_result($result, 0, "access_key");
		} else {
			$key = db_escape_string(sha1(uniqid(rand(), true)));

			$result = db_query($link, "INSERT INTO ttrss_access_keys
				(access_key, feed_id, is_cat, owner_uid)
				VALUES ('$key', '$feed_id', $sql_is_cat, '$owner_uid')");

			return $key;
		}
		return false;
	}

	/**
	 * Extracts RSS/Atom feed URLs from the given HTML URL.
	 *
	 * @param string $url HTML page URL
	 *
	 * @return array Array of feeds. Key is the full URL, value the title
	 */
	function get_feeds_from_html($url, $login = false, $pass = false)
	{
		$url     = fix_url($url);
		$baseUrl = substr($url, 0, strrpos($url, '/') + 1);

		libxml_use_internal_errors(true);

		$content = @fetch_file_contents($url, false, $login, $pass);

		$doc = new DOMDocument();
		$doc->loadHTML($content);
		$xpath = new DOMXPath($doc);
		$entries = $xpath->query('/html/head/link[@rel="alternate"]');
		$feedUrls = array();
		foreach ($entries as $entry) {
			if ($entry->hasAttribute('href')) {
				$title = $entry->getAttribute('title');
				if ($title == '') {
					$title = $entry->getAttribute('type');
				}
				$feedUrl = rewrite_relative_url(
					$baseUrl, $entry->getAttribute('href')
				);
				$feedUrls[$feedUrl] = $title;
			}
		}
		return $feedUrls;
	}

	/**
	 * Checks if the content behind the given URL is a HTML file
	 *
	 * @param string $url URL to check
	 *
	 * @return boolean True if the URL contains HTML content
	 */
	function url_is_html($url, $login = false, $pass = false) {
		$content = substr(fetch_file_contents($url, false, $login, $pass), 0, 1000);

		if (stripos($content, '<html>') === false
			&& stripos($content, '<html ') === false
		) {
			return false;
		}

		return true;
	}

	function print_label_select($link, $name, $value, $attributes = "") {

		$result = db_query($link, "SELECT caption FROM ttrss_labels2
			WHERE owner_uid = '".$_SESSION["uid"]."' ORDER BY caption");

		print "<select default=\"$value\" name=\"" . htmlspecialchars($name) .
			"\" $attributes onchange=\"labelSelectOnChange(this)\" >";

		while ($line = db_fetch_assoc($result)) {

			$issel = ($line["caption"] == $value) ? "selected=\"1\"" : "";

			print "<option value=\"".htmlspecialchars($line["caption"])."\"
				$issel>" . htmlspecialchars($line["caption"]) . "</option>";

		}

#		print "<option value=\"ADD_LABEL\">" .__("Add label...") . "</option>";

		print "</select>";


	}

	function format_article_enclosures($link, $id, $always_display_enclosures,
					$article_content) {

		$result = get_article_enclosures($link, $id);
		$rv = '';

		if (count($result) > 0) {

			$entries_html = array();
			$entries = array();

			foreach ($result as $line) {

				$url = $line["content_url"];
				$ctype = $line["content_type"];

				if (!$ctype) $ctype = __("unknown type");

#				$filename = substr($url, strrpos($url, "/")+1);

				$entry = format_inline_player($link, $url, $ctype);

#				$entry .= " <a target=\"_blank\" href=\"" . htmlspecialchars($url) . "\">" .
#					$filename . " (" . $ctype . ")" . "</a>";

				array_push($entries_html, $entry);

				$entry = array();

				$entry["type"] = $ctype;
				$entry["filename"] = $filename;
				$entry["url"] = $url;

				array_push($entries, $entry);
			}

			$rv .= "<div class=\"postEnclosures\">";

			if (!get_pref($link, "STRIP_IMAGES")) {
				if ($always_display_enclosures ||
							!preg_match("/<img/i", $article_content)) {

					foreach ($entries as $entry) {

						if (preg_match("/image/", $entry["type"]) ||
								preg_match("/\.(jpg|png|gif|bmp)/i", $entry["filename"])) {

								$rv .= "<p><img
								alt=\"".htmlspecialchars($entry["filename"])."\"
								src=\"" .htmlspecialchars($entry["url"]) . "\"/></p>";
						}
					}
				}
			}

			if (count($entries) == 1) {
				$rv .= __("Attachment:") . " ";
			} else {
				$rv .= __("Attachments:") . " ";
			}

			$rv .= join(", ", $entries_html);

			$rv .= "</div>";
		}

		return $rv;
	}

	function getLastArticleId($link) {
		$result = db_query($link, "SELECT MAX(ref_id) AS id FROM ttrss_user_entries
			WHERE owner_uid = " . $_SESSION["uid"]);

		if (db_num_rows($result) == 1) {
			return db_fetch_result($result, 0, "id");
		} else {
			return -1;
		}
	}

	function build_url($parts) {
		return $parts['scheme'] . "://" . $parts['host'] . $parts['path'];
	}

	/**
	 * Converts a (possibly) relative URL to a absolute one.
	 *
	 * @param string $url     Base URL (i.e. from where the document is)
	 * @param string $rel_url Possibly relative URL in the document
	 *
	 * @return string Absolute URL
	 */
	function rewrite_relative_url($url, $rel_url) {
		if (strpos($rel_url, "magnet:") === 0) {
			return $rel_url;
		} else if (strpos($rel_url, "://") !== false) {
			return $rel_url;
		} else if (strpos($rel_url, "/") === 0)
		{
			$parts = parse_url($url);
			$parts['path'] = $rel_url;

			return build_url($parts);

		} else {
			$parts = parse_url($url);
			if (!isset($parts['path'])) {
				$parts['path'] = '/';
			}
			$dir = $parts['path'];
			if (substr($dir, -1) !== '/') {
				$dir = dirname($parts['path']);
				$dir !== '/' && $dir .= '/';
			}
			$parts['path'] = $dir . $rel_url;

			return build_url($parts);
		}
	}

	function sphinx_search($query, $offset = 0, $limit = 30) {
		require_once 'lib/sphinxapi.php';

		$sphinxClient = new SphinxClient();

		$sphinxClient->SetServer('localhost', 9312);
		$sphinxClient->SetConnectTimeout(1);

		$sphinxClient->SetFieldWeights(array('title' => 70, 'content' => 30,
			'feed_title' => 20));

		$sphinxClient->SetMatchMode(SPH_MATCH_EXTENDED2);
		$sphinxClient->SetRankingMode(SPH_RANK_PROXIMITY_BM25);
		$sphinxClient->SetLimits($offset, $limit, 1000);
		$sphinxClient->SetArrayResult(false);
		$sphinxClient->SetFilter('owner_uid', array($_SESSION['uid']));

		$result = $sphinxClient->Query($query, SPHINX_INDEX);

		$ids = array();

		if (is_array($result['matches'])) {
			foreach (array_keys($result['matches']) as $int_id) {
				$ref_id = $result['matches'][$int_id]['attrs']['ref_id'];
				array_push($ids, $ref_id);
			}
		}

		return $ids;
	}

	function cleanup_tags($link, $days = 14, $limit = 1000) {

		if (DB_TYPE == "pgsql") {
			$interval_query = "date_updated < NOW() - INTERVAL '$days days'";
		} else if (DB_TYPE == "mysql") {
			$interval_query = "date_updated < DATE_SUB(NOW(), INTERVAL $days DAY)";
		}

		$tags_deleted = 0;

		while ($limit > 0) {
			$limit_part = 500;

			$query = "SELECT ttrss_tags.id AS id
				FROM ttrss_tags, ttrss_user_entries, ttrss_entries
				WHERE post_int_id = int_id AND $interval_query AND
				ref_id = ttrss_entries.id AND tag_cache != '' LIMIT $limit_part";

			$result = db_query($link, $query);

			$ids = array();

			while ($line = db_fetch_assoc($result)) {
				array_push($ids, $line['id']);
			}

			if (count($ids) > 0) {
				$ids = join(",", $ids);
				print ".";

				$tmp_result = db_query($link, "DELETE FROM ttrss_tags WHERE id IN ($ids)");
				$tags_deleted += db_affected_rows($link, $tmp_result);
			} else {
				break;
			}

			$limit -= $limit_part;
		}

		print "\n";

		return $tags_deleted;
	}

	function print_user_stylesheet($link) {
		$value = get_pref($link, 'USER_STYLESHEET');

		if ($value) {
			print "<style type=\"text/css\">";
			print str_replace("<br/>", "\n", $value);
			print "</style>";
		}

	}

/*	function rewrite_urls($line) {
		global $url_regex;

		$urls = null;

		$result = preg_replace("/((?<!=.)((http|https|ftp)+):\/\/[^ ,!]+)/i",
			"<a target=\"_blank\" href=\"\\1\">\\1</a>", $line);

		return $result;
	} */

	function rewrite_urls($html) {
		libxml_use_internal_errors(true);

		$charset_hack = '<head>
			<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
		</head>';

		$doc = new DOMDocument();
		$doc->loadHTML($charset_hack . $html);
		$xpath = new DOMXPath($doc);

		$entries = $xpath->query('//*/text()');

		foreach ($entries as $entry) {
			if (strstr($entry->wholeText, "://") !== false) {
				$text = preg_replace("/((?<!=.)((http|https|ftp)+):\/\/[^ ,!]+)/i",
					"<a target=\"_blank\" href=\"\\1\">\\1</a>", $entry->wholeText);

				if ($text != $entry->wholeText) {
					$cdoc = new DOMDocument();
					$cdoc->loadHTML($charset_hack . $text);


					foreach ($cdoc->childNodes as $cnode) {
						$cnode = $doc->importNode($cnode, true);

						if ($cnode) {
							$entry->parentNode->insertBefore($cnode);
						}
					}

					$entry->parentNode->removeChild($entry);

				}
			}
		}

		$node = $doc->getElementsByTagName('body')->item(0);

		// http://tt-rss.org/forum/viewtopic.php?f=1&t=970
		if ($node)
			return $doc->saveXML($node, LIBXML_NOEMPTYTAG);
		else
			return $html;
	}

	function filter_to_sql($filter) {
		$query = "";

		$regexp_valid = preg_match('/' . $filter['reg_exp'] . '/',
			$filter['reg_exp']) !== FALSE;

		if ($regexp_valid) {

			if (DB_TYPE == "pgsql")
				$reg_qpart = "~";
			else
				$reg_qpart = "REGEXP";

			switch ($filter["type"]) {
				case "title":
					$query = "LOWER(ttrss_entries.title) $reg_qpart LOWER('".
						$filter['reg_exp'] . "')";
					break;
				case "content":
					$query = "LOWER(ttrss_entries.content) $reg_qpart LOWER('".
						$filter['reg_exp'] . "')";
					break;
				case "both":
					$query = "LOWER(ttrss_entries.title) $reg_qpart LOWER('".
						$filter['reg_exp'] . "') OR LOWER(" .
						"ttrss_entries.content) $reg_qpart LOWER('" . $filter['reg_exp'] . "')";
					break;
				case "tag":
					$query = "LOWER(ttrss_user_entries.tag_cache) $reg_qpart LOWER('".
						$filter['reg_exp'] . "')";
					break;
				case "link":
					$query = "LOWER(ttrss_entries.link) $reg_qpart LOWER('".
						$filter['reg_exp'] . "')";
					break;
				case "date":

					if ($filter["filter_param"] == "before")
						$cmp_qpart = "<";
					else
						$cmp_qpart = ">=";

					$timestamp = date("Y-m-d H:N:s", strtotime($filter["reg_exp"]));
					$query = "ttrss_entries.date_entered $cmp_qpart '$timestamp'";
					break;
				case "author":
					$query = "LOWER(ttrss_entries.author) $reg_qpart LOWER('".
						$filter['reg_exp'] . "')";
					break;
			}

			if ($filter["inverse"])
				$query = "NOT ($query)";

			if ($query) {
				if (DB_TYPE == "pgsql") {
					$query = " ($query) AND ttrss_entries.date_entered > NOW() - INTERVAL '14 days'";
				} else {
					$query = " ($query) AND ttrss_entries.date_entered > DATE_SUB(NOW(), INTERVAL 14 DAY)";
				}
				$query .= " AND ";
			}

			return $query;
		} else {
			return false;
		}
	}

	// Status codes:
	// -1  - never connected
	// 0   - no data received
	// 1   - data received successfully
	// 2   - did not receive valid data
	// >10 - server error, code + 10 (e.g. 16 means server error 6)

	function get_linked_feeds($link, $instance_id = false) {
		if ($instance_id)
			$instance_qpart = "id = '$instance_id' AND ";
		else
			$instance_qpart = "";

		if (DB_TYPE == "pgsql") {
			$date_qpart = "last_connected < NOW() - INTERVAL '6 hours'";
		} else {
			$date_qpart = "last_connected < DATE_SUB(NOW(), INTERVAL 6 HOUR)";
		}

		$result = db_query($link, "SELECT id, access_key, access_url FROM ttrss_linked_instances
			WHERE $instance_qpart $date_qpart ORDER BY last_connected");

		while ($line = db_fetch_assoc($result)) {
			$id = $line['id'];

			_debug("Updating: " . $line['access_url'] . " ($id)");

			$fetch_url = $line['access_url'] . '/public.php?op=fbexport';
			$post_query = 'key=' . $line['access_key'];

			$feeds = fetch_file_contents($fetch_url, false, false, false, $post_query);

			// try doing it the old way
			if (!$feeds) {
				$fetch_url = $line['access_url'] . '/backend.php?op=fbexport';
				$feeds = fetch_file_contents($fetch_url, false, false, false, $post_query);
			}

			if ($feeds) {
				$feeds = json_decode($feeds, true);

				if ($feeds) {
					if ($feeds['error']) {
						$status = $feeds['error']['code'] + 10;
					} else {
						$status = 1;

						if (count($feeds['feeds']) > 0) {

							db_query($link, "DELETE FROM ttrss_linked_feeds
								WHERE instance_id = '$id'");

							foreach ($feeds['feeds'] as $feed) {
								$feed_url = db_escape_string($feed['feed_url']);
								$title = db_escape_string($feed['title']);
								$subscribers = db_escape_string($feed['subscribers']);
								$site_url = db_escape_string($feed['site_url']);

								db_query($link, "INSERT INTO ttrss_linked_feeds
									(feed_url, site_url, title, subscribers, instance_id, created, updated)
								VALUES
									('$feed_url', '$site_url', '$title', '$subscribers', '$id', NOW(), NOW())");
							}
						} else {
							// received 0 feeds, this might indicate that
							// the instance on the other hand is rebuilding feedbrowser cache
							// we will try again later

							// TODO: maybe perform expiration based on updated here?
						}

						_debug("Processed " . count($feeds['feeds']) . " feeds.");
					}
				} else {
					$status = 2;
				}

			} else {
				$status = 0;
			}

			_debug("Status: $status");

			db_query($link, "UPDATE ttrss_linked_instances SET
				last_status_out = '$status', last_connected = NOW() WHERE id = '$id'");

		}
	}

	function make_feed_browser($link, $search, $limit, $mode = 1) {

		$owner_uid = $_SESSION["uid"];
		$rv = '';

		if ($search) {
			$search_qpart = "AND (UPPER(feed_url) LIKE UPPER('%$search%') OR
						UPPER(title) LIKE UPPER('%$search%'))";
		} else {
			$search_qpart = "";
		}

		if ($mode == 1) {
			/* $result = db_query($link, "SELECT feed_url, subscribers FROM
			 ttrss_feedbrowser_cache WHERE (SELECT COUNT(id) = 0 FROM ttrss_feeds AS tf
			WHERE tf.feed_url = ttrss_feedbrowser_cache.feed_url
			AND owner_uid = '$owner_uid') $search_qpart
			ORDER BY subscribers DESC LIMIT $limit"); */

			$result = db_query($link, "SELECT feed_url, site_url, title, SUM(subscribers) AS subscribers FROM
						(SELECT feed_url, site_url, title, subscribers FROM ttrss_feedbrowser_cache UNION ALL
							SELECT feed_url, site_url, title, subscribers FROM ttrss_linked_feeds) AS qqq
						WHERE
							(SELECT COUNT(id) = 0 FROM ttrss_feeds AS tf
								WHERE tf.feed_url = qqq.feed_url
									AND owner_uid = '$owner_uid') $search_qpart
						GROUP BY feed_url, site_url, title ORDER BY subscribers DESC LIMIT $limit");

		} else if ($mode == 2) {
			$result = db_query($link, "SELECT *,
						(SELECT COUNT(*) FROM ttrss_user_entries WHERE
					 		orig_feed_id = ttrss_archived_feeds.id) AS articles_archived
						FROM
							ttrss_archived_feeds
						WHERE
						(SELECT COUNT(*) FROM ttrss_feeds
							WHERE ttrss_feeds.feed_url = ttrss_archived_feeds.feed_url AND
								owner_uid = '$owner_uid') = 0	AND
						owner_uid = '$owner_uid' $search_qpart
						ORDER BY id DESC LIMIT $limit");
		}

		$feedctr = 0;

		while ($line = db_fetch_assoc($result)) {

			if ($mode == 1) {

				$feed_url = htmlspecialchars($line["feed_url"]);
				$site_url = htmlspecialchars($line["site_url"]);
				$subscribers = $line["subscribers"];

				$check_box = "<input onclick='toggleSelectListRow2(this)'
							dojoType=\"dijit.form.CheckBox\"
							type=\"checkbox\" \">";

				$class = ($feedctr % 2) ? "even" : "odd";

				$site_url = "<a target=\"_blank\"
							href=\"$site_url\">
							<span class=\"fb_feedTitle\">".
				htmlspecialchars($line["title"])."</span></a>";

				$feed_url = "<a target=\"_blank\" class=\"fb_feedUrl\"
							href=\"$feed_url\"><img src='images/feed-icon-12x12.png'
							style='vertical-align : middle'></a>";

				$rv .= "<li>$check_box $feed_url $site_url".
							"&nbsp;<span class='subscribers'>($subscribers)</span></li>";

			} else if ($mode == 2) {
				$feed_url = htmlspecialchars($line["feed_url"]);
				$site_url = htmlspecialchars($line["site_url"]);
				$title = htmlspecialchars($line["title"]);

				$check_box = "<input onclick='toggleSelectListRow2(this)' dojoType=\"dijit.form.CheckBox\"
							type=\"checkbox\">";

				$class = ($feedctr % 2) ? "even" : "odd";

				if ($line['articles_archived'] > 0) {
					$archived = sprintf(__("%d archived articles"), $line['articles_archived']);
					$archived = "&nbsp;<span class='subscribers'>($archived)</span>";
				} else {
					$archived = '';
				}

				$site_url = "<a target=\"_blank\"
							href=\"$site_url\">
							<span class=\"fb_feedTitle\">".
				htmlspecialchars($line["title"])."</span></a>";

				$feed_url = "<a target=\"_blank\" class=\"fb_feedUrl\"
							href=\"$feed_url\"><img src='images/feed-icon-12x12.png'
							style='vertical-align : middle'></a>";


				$rv .= "<li id=\"FBROW-".$line["id"]."\">".
							"$check_box $feed_url $site_url $archived</li>";
			}

			++$feedctr;
		}

		if ($feedctr == 0) {
			$rv .= "<li style=\"text-align : center\"><p>".__('No feeds found.')."</p></li>";
		}

		return $rv;
	}

	if (!function_exists('gzdecode')) {
		function gzdecode($string) { // no support for 2nd argument
			return file_get_contents('compress.zlib://data:who/cares;base64,'.
				base64_encode($string));
		}
	}

	function perform_data_import($link, $filename, $owner_uid) {

		$num_imported = 0;
		$num_processed = 0;
		$num_feeds_created = 0;

		$doc = @DOMDocument::load($filename);

		if (!$doc) {
			$contents = file_get_contents($filename);

			if ($contents) {
				$data = @gzuncompress($contents);
			}

			if (!$data) {
				$data = @gzdecode($contents);
			}

			if ($data)
				$doc = DOMDocument::loadXML($data);
		}

		if ($doc) {

			$xpath = new DOMXpath($doc);

			$container = $doc->firstChild;

			if ($container && $container->hasAttribute('schema-version')) {
				$schema_version = $container->getAttribute('schema-version');

				if ($schema_version != SCHEMA_VERSION) {
					print "<p>" .__("Could not import: incorrect schema version.") . "</p>";
					return;
				}

			} else {
				print "<p>" . __("Could not import: unrecognized document format.") . "</p>";
				return;
			}

			$articles = $xpath->query("//article");

			foreach ($articles as $article_node) {
				if ($article_node->childNodes) {

					$ref_id = 0;

					$article = array();

					foreach ($article_node->childNodes as $child) {
						if ($child->nodeName != 'label_cache')
							$article[$child->nodeName] = db_escape_string($child->nodeValue);
						else
							$article[$child->nodeName] = $child->nodeValue;
					}

					//print_r($article);

					if ($article['guid']) {

						++$num_processed;

						//db_query($link, "BEGIN");

						//print 'GUID:' . $article['guid'] . "\n";

						$result = db_query($link, "SELECT id FROM ttrss_entries
							WHERE guid = '".$article['guid']."'");

						if (db_num_rows($result) == 0) {

							$result = db_query($link,
								"INSERT INTO ttrss_entries
									(title,
									guid,
									link,
									updated,
									content,
									content_hash,
									no_orig_date,
									date_updated,
									date_entered,
									comments,
									num_comments,
									author)
								VALUES
									('".$article['title']."',
									'".$article['guid']."',
									'".$article['link']."',
									'".$article['updated']."',
									'".$article['content']."',
									'".sha1($article['content'])."',
									false,
									NOW(),
									NOW(),
									'',
									'0',
									'')");

							$result = db_query($link, "SELECT id FROM ttrss_entries
								WHERE guid = '".$article['guid']."'");

							if (db_num_rows($result) != 0) {
								$ref_id = db_fetch_result($result, 0, "id");
							}

						} else {
							$ref_id = db_fetch_result($result, 0, "id");
						}

						//print "Got ref ID: $ref_id\n";

						if ($ref_id) {

							$feed_url = $article['feed_url'];
							$feed_title = $article['feed_title'];

							$feed = 'NULL';

							if ($feed_url && $feed_title) {
								$result = db_query($link, "SELECT id FROM ttrss_feeds
									WHERE feed_url = '$feed_url' AND owner_uid = '$owner_uid'");

								if (db_num_rows($result) != 0) {
									$feed = db_fetch_result($result, 0, "id");
								} else {
									// try autocreating feed in Uncategorized...

									$result = db_query($link, "INSERT INTO ttrss_feeds (owner_uid,
										feed_url, title) VALUES ($owner_uid, '$feed_url', '$feed_title')");

									$result = db_query($link, "SELECT id FROM ttrss_feeds
										WHERE feed_url = '$feed_url' AND owner_uid = '$owner_uid'");

									if (db_num_rows($result) != 0) {
										++$num_feeds_created;

										$feed = db_fetch_result($result, 0, "id");
									}
								}
							}

							if ($feed != 'NULL')
								$feed_qpart = "feed_id = $feed";
							else
								$feed_qpart = "feed_id IS NULL";

							//print "$ref_id / $feed / " . $article['title'] . "\n";

							$result = db_query($link, "SELECT int_id FROM ttrss_user_entries
								WHERE ref_id = '$ref_id' AND owner_uid = '$owner_uid' AND $feed_qpart");

							if (db_num_rows($result) == 0) {

								$marked = bool_to_sql_bool(sql_bool_to_bool($article['marked']));
								$published = bool_to_sql_bool(sql_bool_to_bool($article['published']));
								$score = (int) $article['score'];

								$tag_cache = $article['tag_cache'];
								$label_cache = db_escape_string($article['label_cache']);
								$note = $article['note'];

								//print "Importing " . $article['title'] . "<br/>";

								++$num_imported;

								$result = db_query($link,
									"INSERT INTO ttrss_user_entries
									(ref_id, owner_uid, feed_id, unread, last_read, marked,
										published, score, tag_cache, label_cache, uuid, note)
									VALUES ($ref_id, $owner_uid, $feed, false,
										NULL, $marked, $published, $score, '$tag_cache',
											'$label_cache', '', '$note')");

								$label_cache = json_decode($label_cache, true);

								if (is_array($label_cache) && $label_cache["no-labels"] != 1) {
									foreach ($label_cache as $label) {

										label_create($link, $label[1],
											$label[2], $label[3], $owner_uid);

										label_add_article($link, $ref_id, $label[1], $owner_uid);

									}
								}

								//db_query($link, "COMMIT");
							}
						}
					}
				}
			}

			print "<p>" .
				T_sprintf("Finished: %d articles processed, %d imported, %d feeds created.",
					$num_processed, $num_imported, $num_feeds_created) .
					"</p>";

		} else {

			print "<p>" . __("Could not load XML document.") . "</p>";

		}
	}

	function get_random_bytes($length) {
		if (function_exists('openssl_random_pseudo_bytes')) {
			return openssl_random_pseudo_bytes($length);
		} else {
			$output = "";

			for ($i = 0; $i < $length; $i++)
				$output .= chr(mt_rand(0, 255));

			return $output;
		}
	}
?>
