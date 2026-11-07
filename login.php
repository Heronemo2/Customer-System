<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Sign in — STRIDE</title>
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
        <a class="logo" href="login.php">STRIDE</a>
        <div>
          <h2>Join the<br />stride.</h2>
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
          <input
            name="phone"
            placeholder="Phone number"
            autocomplete="tel"
            class="su su-required"
            hidden
          />
          <input
            name="address"
            placeholder="Street address"
            autocomplete="street-address"
            class="su su-required"
            hidden
          />
          <div class="two su">
            <input name="city" placeholder="City" class="su-required" /><input
              name="province"
              placeholder="Province"
              class="su-required"
            />
          </div>
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
