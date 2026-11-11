<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Sign in — Quick Step</title>
    <link rel="icon" href="assets/favicon.svg" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link
      href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>" />
  </head>
  <body>
    <div class="auth-wrap">
      <aside class="auth-art">
        <a class="logo" href="login.php">Quick Step</a>
        <div>
          <h2>Join the<br />Quick Step.</h2>
          <p>
            Browse products, keep your order requests organized, review prices,
            and track progress from your customer dashboard.
          </p>
        </div>
        <img src="assets/shoes/cut/TRL-002.svg" alt="" />
      </aside>
      <div class="auth-form">
        <form id="f" class="auth">
          <div class="tabs">
            <button type="button" data-mode="login">Sign in</button
            ><button type="button" data-mode="signup">Create account</button>
          </div>
          <p id="msg" class="muted"></p>
          <div class="two su">
            <input
              name="first_name"
              placeholder="First name"
              autocomplete="given-name"
              class="su-required"
            /><input
              name="last_name"
              placeholder="Last name"
              autocomplete="family-name"
              class="su-required"
            />
          </div>
          <input
            name="username"
            placeholder="Username (3–60 letters, numbers, dots, underscores, or hyphens)"
            autocomplete="username"
            class="su su-required"
            minlength="3"
            maxlength="60"
            hidden
          />
          <div class="su phone-input-row" hidden>
            <input name="phone" type="tel" placeholder="+63 917 123 4567" autocomplete="tel" class="su-required" required />
            <select name="phone_country_code" class="su-required phone-country-code" aria-label="Choose country calling code" required>
              <option value="+63" title="Philippines (+63)" selected>🇵🇭</option>
              <option value="+1" title="United States/Canada (+1)">🇺🇸</option>
              <option value="+44" title="United Kingdom (+44)">🇬🇧</option>
              <option value="+61" title="Australia (+61)">🇦🇺</option>
              <option value="+64" title="New Zealand (+64)">🇳🇿</option>
              <option value="+60" title="Malaysia (+60)">🇲🇾</option>
              <option value="+65" title="Singapore (+65)">🇸🇬</option>
              <option value="+62" title="Indonesia (+62)">🇮🇩</option>
              <option value="+66" title="Thailand (+66)">🇹🇭</option>
              <option value="+84" title="Vietnam (+84)">🇻🇳</option>
              <option value="+81" title="Japan (+81)">🇯🇵</option>
              <option value="+82" title="South Korea (+82)">🇰🇷</option>
              <option value="+86" title="China (+86)">🇨🇳</option>
              <option value="+852" title="Hong Kong (+852)">🇭🇰</option>
              <option value="+886" title="Taiwan (+886)">🇹🇼</option>
              <option value="+91" title="India (+91)">🇮🇳</option>
              <option value="+971" title="United Arab Emirates (+971)">🇦🇪</option>
              <option value="+966" title="Saudi Arabia (+966)">🇸🇦</option>
              <option value="+974" title="Qatar (+974)">🇶🇦</option>
              <option value="+965" title="Kuwait (+965)">🇰🇼</option>
              <option value="+49" title="Germany (+49)">🇩🇪</option>
              <option value="+33" title="France (+33)">🇫🇷</option>
              <option value="+39" title="Italy (+39)">🇮🇹</option>
              <option value="+34" title="Spain (+34)">🇪🇸</option>
              <option value="+31" title="Netherlands (+31)">🇳🇱</option>
              <option value="+353" title="Ireland (+353)">🇮🇪</option>
              <option value="+55" title="Brazil (+55)">🇧🇷</option>
              <option value="+27" title="South Africa (+27)">🇿🇦</option>
            </select>
          </div>
          <div class="two su">
            <select name="province" class="su-required location-province" aria-label="Province" required>
              <option value="">Loading provinces...</option>
            </select>
            <select name="city" class="su-required location-city" aria-label="City or municipality" required disabled>
              <option value="">Select province first</option>
            </select>
          </div>
          <select name="address" class="su su-required location-barangay" aria-label="Barangay" required disabled>
            <option value="">Select city/municipality first to load barangays</option>
          </select>
          <input
            name="email"
            id="emailOrUsername"
            type="text"
            placeholder="Username or email"
            required
            autocomplete="username"
          />
          <input
            name="password"
            type="password"
            placeholder="Password"
            required
          />
          <p id="err" class="error" role="alert"></p>
          <button class="btn" id="submit"></button>
        </form>
      </div>
    </div>
    <script src="js/app.js?v=<?= filemtime(__DIR__ . '/js/app.js') ?>"></script>
  </body>
</html>
