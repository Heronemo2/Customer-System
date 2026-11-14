(() => {
  /* Quick Step storefront UI helpers. */
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const MAX_ORDER_QTY = 1000;
  const peso = (n) =>
    "₱" +
    Number(n || 0).toLocaleString("en-PH", {
      minimumFractionDigits: 0,
      maximumFractionDigits: 2,
    });
  const summaryTotals = (lines) => {
    const subtotal = lines.reduce((sum, line) => {
      const unitPrice = Number(line?.v?.price ?? line?.p?.price ?? line?.p?.sale_price ?? 0);
      const qty = Number(line?.qty ?? 0);
      return sum + unitPrice * qty;
    }, 0);
    const rawShipping = lines.length ? lines[0]?.p?.shipping_fee : 0;
    const shipping = rawShipping == null ? null : Number(rawShipping);
    return {
      subtotal,
      shipping,
      total: shipping == null ? null : subtotal + shipping,
    };
  };
  const esc = (s) =>
    String(s ?? "").replace(
      /[&<>"']/g,
      (c) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#39;",
        })[c],
    );
  const param = (k) => new URLSearchParams(location.search).get(k) || "";
  const PHONE_COUNTRIES = [
    ["Philippines", "+63", "🇵🇭"], ["United States/Canada", "+1", "🇺🇸"], ["United Kingdom", "+44", "🇬🇧"],
    ["Australia", "+61", "🇦🇺"], ["New Zealand", "+64", "🇳🇿"], ["Malaysia", "+60", "🇲🇾"],
    ["Singapore", "+65", "🇸🇬"], ["Indonesia", "+62", "🇮🇩"], ["Thailand", "+66", "🇹🇭"],
    ["Vietnam", "+84", "🇻🇳"], ["Japan", "+81", "🇯🇵"], ["South Korea", "+82", "🇰🇷"], ["China", "+86", "🇨🇳"],
    ["Hong Kong", "+852", "🇭🇰"], ["Taiwan", "+886", "🇹🇼"], ["India", "+91", "🇮🇳"],
    ["United Arab Emirates", "+971", "🇦🇪"], ["Saudi Arabia", "+966", "🇸🇦"], ["Qatar", "+974", "🇶🇦"],
    ["Kuwait", "+965", "🇰🇼"], ["Germany", "+49", "🇩🇪"], ["France", "+33", "🇫🇷"], ["Italy", "+39", "🇮🇹"],
    ["Spain", "+34", "🇪🇸"], ["Netherlands", "+31", "🇳🇱"], ["Ireland", "+353", "🇮🇪"],
    ["Brazil", "+55", "🇧🇷"], ["South Africa", "+27", "🇿🇦"],
  ];
  const phoneCountryOptions = (selected = "+63") => PHONE_COUNTRIES.map(([name, code]) =>
    `<option value="${code}" title="${name} (${code})"${code === selected ? " selected" : ""}>${PHONE_COUNTRIES.find((item) => item[1] === code)?.[2] || "🌐"}</option>`,
  ).join("");
  function splitSavedPhone(value) {
    const phone = String(value || "").trim();
    if (phone.startsWith("+")) {
      const digits = phone.replace(/\D/g, "");
      const match = PHONE_COUNTRIES.map(([, code]) => code).sort((a, b) => b.length - a.length).find((code) => digits.startsWith(code.slice(1)));
      if (match) {
        let number = digits.slice(match.length - 1);
        if (match === "+63" && number.startsWith("0")) number = number.slice(1);
        return { code: match, number };
      }
    }
    return { code: "+63", number: phone };
  }
  function bindPhoneInput(root) {
    const country = $(".phone-country-code", root);
    const input = $("[name=phone]", root);
    if (!country || !input) return;
    let previousCode = country.value;
    input.addEventListener("focus", () => {
      if (!input.value.trim()) input.value = `${country.value} `;
    });
    country.addEventListener("change", () => {
      const digits = input.value.replace(/\D/g, "");
      const oldDigits = previousCode.replace(/\D/g, "");
      const national = input.value.trim().startsWith("+") && digits.startsWith(oldDigits)
        ? digits.slice(oldDigits.length)
        : digits;
      input.value = `${country.value} ${national}`.trimEnd() + (national ? "" : " ");
      previousCode = country.value;
    });
  }

  const PSGC_API = "https://psgc.gitlab.io/api";
  const locationCache = new Map();
  async function psgcList(path) {
    if (!locationCache.has(path)) {
      locationCache.set(path, fetch(`${PSGC_API}/${path}`).then(async (response) => {
        if (!response.ok) throw new Error("Could not load Philippine location data.");
        const payload = await response.json();
        // Accept both static PSGC arrays and wrapped API collections.
        const items = Array.isArray(payload)
          ? payload
          : Array.isArray(payload?.data)
            ? payload.data
            : Array.isArray(payload?.data?.data)
              ? payload.data.data
              : null;
        if (!items) throw new Error("Philippine location data is unavailable.");
        return items;
      }).catch((error) => {
        locationCache.delete(path);
        throw error;
      }));
    }
    return locationCache.get(path);
  }

  function locationKey(value) {
    return String(value || "").toLowerCase().replace(/^(city of )/, "").replace(/ city$/, "").replace(/[^a-z0-9]/g, "");
  }

  async function setupLocationFields(root, saved = {}) {
    const province = $(".location-province", root);
    const city = $(".location-city", root);
    const barangay = $(".location-barangay", root);
    if (!province || !city || !barangay) return;
    const setOptions = (select, items, placeholder, selected = "") => {
      select.replaceChildren(new Option(placeholder, ""));
      items.slice().sort((a, b) => a.name.localeCompare(b.name)).forEach((item) => {
        const option = new Option(item.name, item.name);
        select.add(option);
      });
      const match = [...select.options].find((option) => option.value && (option.value === selected || locationKey(option.value) === locationKey(selected)));
      select.value = match ? match.value : "";
    };
    province.disabled = true;
    try {
      const provinces = await psgcList("provinces");
      setOptions(province, provinces, "Select province", saved.province || "");
      [...province.options].forEach((option) => {
        const item = provinces.find((entry) => entry.name === option.value);
        if (item) option.dataset.code = item.code;
      });
      province.disabled = false;
      const loadCities = async (selectedCity = "") => {
        city.disabled = true;
        barangay.disabled = true;
        setOptions(city, [], province.value ? "Loading cities/municipalities..." : "Select province first");
        setOptions(barangay, [], "Select city/municipality first to load barangays");
        if (!province.value) return;
        const p = encodeURIComponent(province.selectedOptions[0]?.dataset.code || "");
        const cities = await psgcList(`provinces/${p}/cities-municipalities/`);
        setOptions(city, cities, "Select city or municipality", selectedCity);
        [...city.options].forEach((option) => {
          const item = cities.find((entry) => entry.name === option.value);
          if (item) option.dataset.code = item.code;
        });
        city.disabled = false;
        if (city.value) await loadBarangays(saved.address || "");
      };
      const loadBarangays = async (selectedBarangay = "") => {
        barangay.disabled = true;
        setOptions(barangay, [], "Loading barangays...");
        const c = encodeURIComponent(city.selectedOptions[0]?.dataset.code || "");
        const items = await psgcList(`cities-municipalities/${c}/barangays/`);
        setOptions(barangay, items, "Select barangay", selectedBarangay);
        barangay.disabled = false;
      };
      province.addEventListener("change", () => loadCities().catch(showLocationError));
      city.addEventListener("change", () => {
        if (!city.value) {
          barangay.disabled = true;
          return;
        }
        loadBarangays().catch(showLocationError);
      });
      if (province.value) await loadCities(saved.city || "");
    } catch (error) {
      showLocationError(error);
    }
    function showLocationError(error) {
      const message = $("#profileMsg", root) || $("#err", root) || $("#msg", root);
      if (message) message.textContent = error.message;
      province.disabled = true;
      city.disabled = true;
      barangay.disabled = true;
    }
  }

  function toast(message) {
    let t = $("#toast");
    if (!t) {
      t = document.createElement("div");
      t.id = "toast";
      t.className = "toast";
      t.setAttribute("role", "status");
      document.body.append(t);
    }
    t.textContent = message;
    t.classList.add("show");
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => t.classList.remove("show"), 2600);
  }

  async function api(url, data) {
    const response = await fetch(
      url,
      data
        ? {
          method: "POST",
          ...(data instanceof FormData
            ? { body: data }
            : {
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify(data),
            }),
        }
        : {},
    );
    const body = await response.text();
    let result;
    try {
      result = body ? JSON.parse(body) : {};
    } catch {
      const error = new Error(
        `Server returned an invalid response (HTTP ${response.status}). Check the PHP/Apache error log.`,
      );
      error.status = response.status;
      throw error;
    }
    if (!response.ok) {
      const error = new Error(
        result.error || `Request failed (HTTP ${response.status}).`,
      );
      error.status = response.status;
      throw error;
    }
    return result;
  }

  const Cart = {
    get: () => JSON.parse(localStorage.getItem("stride_cart") || "[]").map((item) => ({
      ...item,
      qty: Math.min(MAX_ORDER_QTY, Math.max(1, parseInt(item.qty) || 1)),
    })),
    set(cart) {
      localStorage.setItem("stride_cart", JSON.stringify(cart));
      Cart.badge();
    },
    count: () => Cart.get().length,
    add(productId, variantId, quantity = 1) {
      const cart = Cart.get();
      const item = cart.find((entry) => entry.variant_id === variantId);
      const nextQty = (item ? Number(item.qty) : 0) + Number(quantity);
      if (item) item.qty = Math.min(MAX_ORDER_QTY, nextQty);
      else
        cart.push({
          product_id: productId,
          variant_id: variantId,
          qty: Math.min(MAX_ORDER_QTY, nextQty),
        });
      Cart.set(cart);
      return nextQty <= MAX_ORDER_QTY;
    },
    setQty(variantId, quantity) {
      Cart.set(
        Cart.get()
          .map((item) =>
            item.variant_id == variantId
              ? { ...item, qty: Math.min(MAX_ORDER_QTY, Number(quantity)) }
              : item,
          )
          .filter((item) => item.qty > 0),
      );
    },
    remove(variantId) {
      Cart.set(Cart.get().filter((item) => item.variant_id != variantId));
    },
    clear() {
      Cart.set([]);
    },
    badge() {
      const badge = $("#cartCount");
      if (badge) {
        badge.textContent = Cart.count();
        badge.hidden = !Cart.count();
      }
    },
    async lines() {
      const cart = Cart.get();
      if (!cart.length) return [];
      const { products, shipping_quote: shippingQuote = null, shipping_error: shippingError = null } = await api(
        "api/products.php?ids=" +
        [...new Set(cart.map((item) => item.product_id))].join(","),
      );
      const address = await mePromise;
      return cart
        .map((item) => {
          const product = products.find((entry) =>
            entry.id == item.product_id || entry.variants.some((variant) => variant.id == item.variant_id),
          );
          const variant =
            product &&
            product.variants.find((entry) => entry.id == item.variant_id);
          return product && variant
            ? {
              p: { ...product, shipping_fee: address && shippingQuote ? Number(shippingQuote.shipping_fee) : null, shipping_quote: shippingQuote, shipping_error: shippingError },
              v: variant,
              qty: Math.max(1, parseInt(item.qty) || 1),
              wanted: item.qty,
            }
            : null;
        })
        .filter(Boolean);
    },
  };

  const mePromise = api("api/auth.php?action=me")
    .then((result) => result.user)
    .catch(() => null);
  let ME = null;

  async function requireLogin() {
    ME = await mePromise;
    if (!ME) {
      location.replace(
        "login.php?next=" +
        encodeURIComponent(
          location.pathname.split("/").pop() + location.search,
        ) +
        "&msg=" +
        encodeURIComponent("Sign in to continue"),
      );
      return new Promise(() => { });
    }
    return ME;
  }

  const skel = (count) =>
    Array.from(
      { length: count },
      () =>
        '<div class="card skel"><div class="shot"></div><div class="info"><i></i><i></i></div></div>',
    ).join("");

  function productCard(product) {
    const outOfStock = product.variants.every((variant) => variant.stock < 1);
    return `<button class="card product-select-card" type="button" data-order-product="${product.id}"><div class="shot"><img src="${esc(product.image_url)}" alt="" loading="lazy"></div><div class="info"><span class="cat">Quick Step</span><h3>${esc(product.name)}</h3>${outOfStock ? '<span class="muted">Available to request · may need replenishment</span>' : ""}</div></button>`;
  }

  function orderHtml(order) {
    let status = order.delivery_status || order.order_status;
    if (order.order_admin_status === "Rejected") {
      status = "Sales order declined";
    } else if (order.order_admin_status === "Pending" && order.order_no) {
      status = "Awaiting order approval";
    } else if (!status && order.po_status === "Open") {
      status = "Purchase order open";
    } else if (
      !status &&
      order.status === "Pending Quotation" &&
      order.quotation_status === "Rejected"
    ) {
      status = "Awaiting revised quotation";
    } else if (!status) {
      status = order.status || "Pending";
    }

    const items = (order.items || [])
      .map(
        (item) =>
          `<div class="oi"><img src="${esc(item.image_url || "assets/shoes/cut/RUN-001.svg")}" alt=""><div class="oi-info"><strong>${esc(item.product_name || order.product_code || "Product")}</strong><br><span class="muted">Code ${esc(order.product_code || "")}${item.size ? ` · Size ${esc(item.size)}` : ""} · Qty ${esc(item.quantity ?? order.quantity ?? "")}</span></div></div>`,
      )
      .join("");
    const fulfillment = [
      order.po_no && `PO ${order.po_no}${order.po_status ? ` (${order.po_status})` : ""}`,
      order.order_no &&
        `Sales order ${order.order_no}${order.order_status ? ` (${order.order_status}${order.order_admin_status === "Pending" ? ", awaiting approval" : ""})` : ""}`,
      order.delivery_no &&
        `Delivery ${order.delivery_no}${order.delivery_status ? ` (${order.delivery_status})` : ""}`,
      order.invoice_no &&
        `Invoice ${order.invoice_no}${order.invoice_status ? ` (${order.invoice_status})` : ""}`,
      order.payment_status && `Payment ${order.payment_status}`,
    ]
      .filter(Boolean)
      .join(" · ");

    return `<article class="order"><header><div><strong>${esc(order.request_no || order.order_number || "Order request")}</strong><br><span class="muted">${esc(order.request_date || order.created_at || "")}</span></div><span class="status">${esc(status)}</span></header>${items}<p class="muted">Quotation: ${esc(order.quotation_no || "Not available yet")}${fulfillment ? ` · ${esc(fulfillment)}` : ""}</p><p class="order-links"><a href="progress.php#${encodeURIComponent(order.request_no || "")}">Track progress →</a></p></article>`;
  }

  const isExpiredQuotation = (quotation) => {
    if (quotation.customer_status !== "Pending" || !quotation.valid_until) {
      return false;
    }
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const validUntil = new Date(`${quotation.valid_until}T00:00:00`);
    return validUntil < today;
  };

  function quotationHtml(quotation, { actions = true, order = null } = {}) {
    const expired = isExpiredQuotation(quotation);
    const pending = quotation.customer_status === "Pending" && !expired;
    const statusText = expired ? "Expired" : quotation.customer_status;
    return `<article class="order"><header><div><strong>${esc(quotation.quotation_no)}</strong><br><span class="muted">${esc(quotation.product_code)}${quotation.size ? ` · Size ${esc(quotation.size)}` : ""} · ${esc(quotation.quotation_date || "")}</span></div><strong>${peso(quotation.total_amount)}</strong></header><p>Quantity: ${esc(quotation.quantity)} · Status: <span class="status">${esc(statusText)}</span>${quotation.valid_until ? ` · Valid until ${esc(quotation.valid_until)}` : ""}</p>${order && (order.po_no || order.order_no) ? `<p class="muted">Purchase order: ${esc(order.po_no || "—")} · Order: ${esc(order.order_no || "—")}</p>` : ""}<div class="cta"><a class="btn dark" href="view_quotation.php?id=${encodeURIComponent(quotation.sq_id)}" target="_blank" rel="noopener">View quotation</a>${actions && pending ? `<button class="btn" data-approve="${quotation.sq_id}">Accept quotation</button><button class="btn dark" data-reject="${quotation.sq_id}">Decline</button>` : ""}${actions && expired ? `<button class="btn dark" data-delete="${quotation.sq_id}">Delete quotation</button>` : ""}</div></article>`;
  }

  function bindQuotationDecisions(host, onChange) {
    host.addEventListener("click", async (event) => {
      const button = event.target.closest("[data-approve],[data-reject],[data-delete]");
      if (!button) return;
      const quoteId = +(button.dataset.approve || button.dataset.reject || button.dataset.delete);
      if (button.dataset.delete) {
        if (!confirm("Delete this expired quotation?")) return;
        button.disabled = true;
        try {
          const result = await api("api/quotations.php", {
            action: "delete",
            quotation_id: quoteId,
          });
          toast(result.message);
          onChange();
        } catch (error) {
          toast(error.message);
          button.disabled = false;
        }
        return;
      }
      const decision = button.dataset.approve ? "Approved" : "Rejected";
      if (
        !confirm(
          `${decision === "Approved" ? "Accept" : "Decline"} this quotation?`,
        )
      )
        return;
      button.disabled = true;
      try {
        const result = await api("api/quotations.php", {
          quotation_id: quoteId,
          decision,
        });
        toast(result.message);
        onChange();
      } catch (error) {
        toast(error.message);
        button.disabled = false;
      }
    });
  }

  function layout() {
    Cart.badge();
    const sidebar = $("#sidebar");
    if (!sidebar) return;

    const setOpen = (open) => {
      sidebar.classList.toggle("open", open);
      $("#scrim").hidden = !open;
      document.body.classList.toggle("nav-open", open);
      $("#menuBtn").setAttribute("aria-expanded", String(open));
    };
    $("#menuBtn").onclick = () => setOpen(!sidebar.classList.contains("open"));
    $("#scrim").onclick = () => setOpen(false);
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") setOpen(false);
    });

    $("#signOut").onclick = async () => {
      try {
        await api("api/auth.php?action=logout", {});
      } finally {
        Cart.clear();
        location.href = "login.php";
      }
    };

    mePromise.then((user) => {
      if (!user) return;
      const name = user.name || user.first_name || "Customer";
      const initials = name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0])
        .join("")
        .toUpperCase();
      $("#userAvatar").textContent = initials;
      if (user.avatar_path) {
        $("#userAvatar").innerHTML = `<img src="${esc(user.avatar_path)}" alt="">`;
      }
      $("#userName").textContent = name;
      $("#userEmail").textContent = user.email || user.username || "";
      api("api/quotations.php")
        .then(({ quotations }) => {
          const pending = quotations.filter(
            (quotation) =>
              quotation.customer_status === "Pending" &&
              !isExpiredQuotation(quotation),
          ).length;
          const badge = $("#quoteCount");
          if (!badge) return;
          badge.textContent = String(pending);
          badge.hidden = pending === 0;
        })
        .catch(() => {});
    });
  }

  document.addEventListener("DOMContentLoaded", layout);
  document.addEventListener("click", (event) => {
    const button = event.target.closest("button");
    if (!button) return;
    if (button.dataset.d) {
      const item = Cart.get().find(
        (entry) => entry.variant_id == button.dataset.v,
      );
      if (item) Cart.setQty(+button.dataset.v, item.qty + +button.dataset.d);
      if (typeof window.STRIDE_CART_REDRAW === "function")
        window.STRIDE_CART_REDRAW();
    }
    if (button.dataset.rm) {
      Cart.remove(+button.dataset.rm);
      if (typeof window.STRIDE_CART_REDRAW === "function")
        window.STRIDE_CART_REDRAW();
    }
  });

  {
    if (document.querySelector("#profile")) {
      const profileHost = $("#profile");

      if (profileHost) {
        (async () => {
          try {
            const user = await requireLogin();
            const initials = user.name
              .trim()
              .split(/\s+/)
              .slice(0, 2)
              .map((part) => part[0])
              .join("")
              .toUpperCase();
            const avatarPreview = user.avatar_path
              ? `<img id="profileAvatar" src="${esc(user.avatar_path)}" alt="Profile photo">`
              : `<span id="profileAvatar">${esc(initials)}</span>`;
            const savedPhone = splitSavedPhone(user.phone);
            profileHost.innerHTML = `<form id="profileForm" class="box profile-form" enctype="multipart/form-data"><h2>Personal details</h2><div class="profile-photo-field"><div class="profile-photo-preview" id="profilePhotoPreview">${avatarPreview}</div><label>Profile photo<input name="avatar" id="profilePhotoInput" type="file" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG, or WebP · maximum 2 MB</small></label></div><label>Full name<input name="name" value="${esc(user.name)}" autocomplete="name" required></label><label>Email address<input name="email" type="email" value="${esc(user.email)}" autocomplete="email"></label><label for="profilePhoneNumber">Phone number</label><div class="phone-input-row"><input id="profilePhoneNumber" name="phone" type="tel" value="${esc(savedPhone.code)} ${esc(savedPhone.number)}" autocomplete="tel" required><select name="phone_country_code" class="phone-country-code" aria-label="Choose country calling code" required>${phoneCountryOptions(savedPhone.code)}</select></div><div class="two"><label>Province<select name="province" class="location-province" required><option value="">Loading provinces...</option></select></label><label>City/Municipality<select name="city" class="location-city" required disabled><option value="">Select province first</option></select></label></div><label>Barangay<select name="address" class="location-barangay" required disabled><option value="">Select city/municipality first to load barangays</option></select></label><p id="profileMsg" class="muted" role="status"></p><button class="btn">Save changes</button></form>`;
            bindPhoneInput(profileHost);
            setupLocationFields(profileHost, { province: user.province, city: user.city, address: user.address });
            $("#profilePhotoInput").onchange = (event) => {
              const file = event.target.files[0];
              if (!file) return;
              if (file.size > 2 * 1024 * 1024) {
                event.target.value = "";
                $("#profileMsg").textContent =
                  "Profile photos must be 2 MB or smaller.";
                return;
              }
              const previewUrl = URL.createObjectURL(file);
              $("#profilePhotoPreview").innerHTML =
                `<img id="profileAvatar" src="${previewUrl}" alt="Profile photo preview">`;
            };
            $("#profileForm").onsubmit = async (event) => {
              event.preventDefault();
              const form = event.currentTarget;
              const button = form.querySelector("button");
              button.disabled = true;
              try {
                const result = await api("api/profile.php", new FormData(form));
                $("#profileMsg").textContent = result.message;
                if (result.avatar_path) {
                  $("#profilePhotoPreview").innerHTML =
                    `<img id="profileAvatar" src="${esc(result.avatar_path)}?v=${Date.now()}" alt="Profile photo">`;
                  if ($("#userAvatar")) {
                    $("#userAvatar").innerHTML =
                      `<img src="${esc(result.avatar_path)}?v=${Date.now()}" alt="">`;
                  }
                }
                if (result.name && $("#userName")) {
                  $("#userName").textContent = result.name;
                }
                toast(result.message);
              } catch (error) {
                $("#profileMsg").textContent = error.message;
              } finally {
                button.disabled = false;
              }
            };
          } catch (error) {
            profileHost.innerHTML = `<p class="error">${esc(error.message)}</p>`;
          }
        })();
      }
    }
  }

  {
    if (document.querySelector("#lines") && document.querySelector("#go")) {
      async function draw() {
        let L = [];
        try {
          L = await Cart.lines();
        } catch {
          $("#lines").innerHTML =
            '<p class="error">Could not load your request cart.</p>';
          return;
        }
        $("#lines").innerHTML = L.length
          ? L.map(
            (l) =>
              `<div class="line"><a href="product.php?id=${l.p.id}"><img src="${esc(l.p.image_url)}" alt=""></a><div><strong>${esc(l.p.name)}</strong><br><span class="muted">${esc(l.v.size)} / Code ${esc(l.v.code || l.p.sku)}</span><div class="qty"><button data-d="-1" data-v="${l.v.id}" aria-label="Decrease">−</button>${l.qty}<button data-d="1" data-v="${l.v.id}" aria-label="Increase" ${l.qty >= MAX_ORDER_QTY ? "disabled" : ""}>+</button></div><button class="rm" data-rm="${l.v.id}">Remove</button></div></div>`,
          ).join("")
          : '<div class="box"><p>Your cart is empty.</p><a class="btn" href="dashboard.php">Browse products</a></div>';
        const totals = summaryTotals(L);
        $("#sShip").textContent = totals.shipping == null ? "Sign in to calculate" : peso(totals.shipping);
        $("#go").style.display = L.length ? "" : "none";
        $("#note").textContent = L.length
          ? (totals.shipping == null ? (L[0]?.p?.shipping_error || "Sign in and complete your delivery address to calculate road shipping.") : `Road distance: ${Number(L[0]?.p?.shipping_quote?.distance_km || 0).toFixed(2)} km at ${peso(L[0]?.p?.shipping_quote?.rate_per_km || 0)}/km. The fixed inventory price will be used in your quotation.`)
          : "";
      }
      window.STRIDE_CART_REDRAW = draw;
      draw();
    }
  }

  {
    if (document.querySelector("#co")) {
      const form = $("#co");
      (async () => {
        const user = await requireLogin();
        let lines = [];
        try {
          lines = await Cart.lines();
        } catch {
          $("#lines").innerHTML =
            '<p class="error">Could not load your cart. Please try again.</p>';
          return;
        }
        if (!lines.length) return location.replace("cart.php");
        $("#who").textContent = `${user.name} · ${user.email}`;
        $("#lines").innerHTML = lines
          .map(
            (line) =>
              `<div class="oi"><img src="${esc(line.p.image_url)}" alt=""><div class="oi-info"><strong>${esc(line.p.name)}</strong><br><span class="muted">${esc(line.v.size)} / ${esc(line.v.code || line.p.sku)} · Qty ${line.qty}</span></div></div>`,
          )
          .join("");
        const totals = summaryTotals(lines);
        $("#sShip").textContent = totals.shipping == null ? "Rate unavailable" : peso(totals.shipping);
        const shippingUnavailable = totals.shipping == null;
        $("#err").textContent = shippingUnavailable
          ? (lines[0]?.p?.shipping_error || "Road shipping could not be calculated. Check your barangay, city, and province, or contact Sales.")
          : "";
        $("#place").disabled = shippingUnavailable;
        form.onsubmit = async (event) => {
          event.preventDefault();
          const button = $("#place");
          const errorHost = $("#err");
          button.disabled = true;
          errorHost.textContent = "";
          try {
            const result = await api("api/checkout.php", {
              note: form.note.value,
              items: Cart.get().map((item) => ({
                product_id: item.product_id,
                variant_id: item.variant_id,
                quantity: item.qty,
              })),
            });
            Cart.clear();
            location.href =
              "order-success.php?o=" +
              encodeURIComponent(result.request_number || result.order_number);
          } catch (error) {
            errorHost.textContent = error.message;
            button.disabled = false;
          }
        };
      })();
    }
  }

  {
    if (document.querySelector("#grid") && document.querySelector("#sort")) {
      const S = {
        category: param("category"),
        q: param("q"),
        sort: param("sort"),
      };
      let cats = [];
      let timer;
      const orderDialog = $("#orderDialog");
      const requestedProductId = param("product_id");

      async function openOrderDialog(productId) {
        const error = $("#orderError");
        error.textContent = "";
        try {
          const { products } = await api("api/products.php?id=" + encodeURIComponent(productId));
          const product = products[0];
          if (!product) throw new Error("This product is no longer available.");
          $("#orderProductName").textContent = product.name;
          $("#orderProductDescription").textContent = product.description || "Select a size and quantity to request a quotation.";
          $("#orderVariant").innerHTML = '<option value="">Select a size</option>' + product.variants.map((variant) => {
            const sizeNumber = String(variant.size).match(/\d+/);
            return `<option value="${variant.id}">${esc(sizeNumber ? sizeNumber[0] : variant.size)}</option>`;
          }).join("");
          $("#orderQuantity").value = "1";
          orderDialog.showModal();
        } catch (error) {
          toast(error.message || "Could not load this product.");
        }
      }

      function chips() {
        $("#chips").innerHTML = [{ slug: "", name: "All" }, ...cats]
          .map(
            (c) =>
              `<button type="button" class="chip ${S.category === c.slug ? "on" : ""}" data-cat="${esc(c.slug)}">${esc(c.name)}</button>`,
          )
          .join("");
      }

      async function load() {
        $("#grid").innerHTML = skel(8);
        const qs = new URLSearchParams();
        Object.entries(S).forEach(([k, v]) => v && qs.set(k, v));
        history.replaceState(
          null,
          "",
          "ordering.php" + (qs.toString() ? "?" + qs : ""),
        );
        try {
          const d = await api("api/products.php?" + qs);
          if (!cats.length && d.categories?.length) {
            cats = d.categories;
          }
          chips();
          $("#count").textContent =
            `${d.products.length} product${d.products.length === 1 ? "" : "s"} available`;
          $("#grid").innerHTML = d.products.length
            ? d.products.map(productCard).join("")
            : '<p class="muted">No products match. Try another search.</p>';
        } catch {
          $("#grid").innerHTML =
            '<p class="error">Could not load products.</p>';
        }
      }

      $("#q").value = S.q;
      $("#sort").value = S.sort;
      $("#searchForm").onsubmit = (event) => {
        event.preventDefault();
        S.q = $("#q").value.trim();
        load();
      };
      $("#q").oninput = () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
          S.q = $("#q").value.trim();
          load();
        }, 300);
      };
      $("#sort").onchange = (event) => {
        S.sort = event.target.value;
        load();
      };
      $("#chips").onclick = (event) => {
        const chip = event.target.closest("[data-cat]");
        if (!chip) return;
        S.category = chip.dataset.cat;
        load();
      };

      $("#grid").addEventListener("click", (event) => {
        const card = event.target.closest("[data-order-product]");
        if (card) openOrderDialog(card.dataset.orderProduct);
      });
      $("#closeOrderDialog").onclick = () => orderDialog.close();
      orderDialog.addEventListener("click", (event) => {
        if (event.target === orderDialog) orderDialog.close();
      });
      $("#orderForm").onsubmit = async (event) => {
        event.preventDefault();
        const submit = $("#orderSubmit");
        const errorHost = $("#orderError");
        const variantId = Number($("#orderVariant").value);
        const quantity = Number($("#orderQuantity").value);
        errorHost.textContent = "";
        if (!variantId || !Number.isInteger(quantity) || quantity < 1 || quantity > MAX_ORDER_QTY) {
          errorHost.textContent = "Select a size and enter a quantity from 1 to 1,000.";
          return;
        }
        submit.disabled = true;
        try {
          await requireLogin();
          const result = await api("api/checkout.php", {
            items: [{ variant_id: variantId, quantity }],
          });
          location.href = "order-success.php?o=" + encodeURIComponent(result.request_number || result.order_number);
        } catch (requestError) {
          errorHost.textContent = requestError.message || "Could not send the quotation request.";
          submit.disabled = false;
        }
      };

      mePromise.then((user) => {
        if (user?.first_name)
          $("#welcome").textContent = `WELCOME BACK, ${user.first_name.toUpperCase()}`;
      });
      api("api/quotations.php")
        .then(({ quotations }) => {
          const pending = quotations.filter(
            (quotation) => quotation.customer_status === "Pending",
          ).length;
          if (!pending) return;
          const banner = $("#reviewBanner");
          banner.innerHTML = `<span>${pending} quotation${pending === 1 ? " needs" : "s need"} your review.</span><a href="quotations.php?filter=pending">Review now →</a>`;
          banner.hidden = false;
        })
        .catch(() => {});
      load().then(() => {
        if (requestedProductId) openOrderDialog(requestedProductId);
      });
    }
  }

  {
    if (document.querySelector("#f")) {
      let mode = param("mode") === "signup" ? "signup" : "login";
      const next = /^[\w-]+\.php(\?[\w=&%.-]*)?$/.test(param("next"))
        ? param("next")
        : "dashboard.php";
      function setMode(m) {
        mode = m;
        $$(".tabs button").forEach((b) =>
          b.classList.toggle("on", b.dataset.mode === m),
        );
        $$(".su").forEach((e) => (e.hidden = m !== "signup"));
        $$(".su-required").forEach((e) => (e.required = m === "signup"));
        $("#submit").textContent =
          m === "signup" ? "Create account" : "Sign in";
        const password = $("#f [name=password]");
        const emailOrUsername = $("#emailOrUsername");
        password.autocomplete =
          m === "signup" ? "new-password" : "current-password";
        password.minLength = m === "signup" ? 8 : 0;
        password.placeholder =
          m === "signup" ? "Password (8+ characters)" : "Password";
        emailOrUsername.type = m === "signup" ? "email" : "text";
        emailOrUsername.placeholder =
          m === "signup" ? "Email address" : "Username or email";
        emailOrUsername.autocomplete =
          m === "signup" ? "email" : "username";
        $("#err").textContent = "";
      }
      setupLocationFields(document, {});
      $("#msg").textContent = param("msg");
      setMode(mode);
      bindPhoneInput(document);
      $$(".tabs button").forEach(
        (b) => (b.onclick = () => setMode(b.dataset.mode)),
      );
      mePromise.then((u) => {
        if (u) location.replace(next);
      });
      $("#f").onsubmit = async (e) => {
        e.preventDefault();
        const f = Object.fromEntries(new FormData(e.target));
        if (
          mode === "signup" &&
          (!f.first_name?.trim() || !f.last_name?.trim())
        )
          return ($("#err").textContent = "Enter your first and last name");
        if (mode === "signup") {
          f.username = (f.username || "").trim();
          f.email = (f.email || "").trim();
          if (!/^[\p{L}\p{N}._-]{3,60}$/u.test(f.username)) {
            $("#err").textContent =
              "Enter a username with 3–60 letters or numbers; dots, underscores, and hyphens are also allowed.";
            return;
          }
        } else {
          f.email = (f.email || "").trim();
        }
        $("#submit").disabled = true;
        try {
          const result = await api(
            "api/auth.php?action=" + (mode === "signup" ? "register" : "login"),
            f,
          );
          if (mode === "signup") {
            const message = result.message || "Your account request was submitted for verification.";
            e.target.reset();
            setMode("login");
            $("#submit").disabled = false;
            $("#msg").textContent = message;
          } else {
            location.href = next;
          }
        } catch (x) {
          $("#err").textContent = x.message;
          $("#submit").disabled = false;
        }
      };
    }
  }

  {
    if (document.querySelector("#pdp")) {
      let P = null,
        V = null;
      (async () => {
        try {
          P = (
            await api("api/products.php?id=" + encodeURIComponent(param("id")))
          ).products[0];
          if (!P) throw 0;
          V = null;
          document.title = P.name + " — Quick Step";
          $("#pdp").innerHTML =
            `<div class="crumbs"><a href="dashboard.php">Products</a> / ${esc(P.sku)}</div><div class="pdp"><div class="big"><img src="${esc(P.image_url)}" alt="${esc(P.name)}"></div><div><span class="cat">${esc(P.brand || P.category || "Product")}</span><h1>${esc(P.name)}</h1><p>${esc(P.description || "A footwear style from the Quick Step collection.")}</p><div class="qtyrow"><label>Quantity <input id="qty" type="number" min="1" max="1000" value="1"></label></div><button class="btn" id="addBtn">Add to request</button><p class="muted">Maximum 1,000 units per product.</p></div></div>`;
          $("#qty").closest(".qtyrow").insertAdjacentHTML(
            "beforebegin",
            `<div class="qtyrow"><label>Size <select id="sizeSelect" required><option value="">Select a size</option>${P.variants.map((variant) => { const sizeNumber = String(variant.size).match(/\d+/); return `<option value="${variant.id}">${esc(sizeNumber ? sizeNumber[0] : variant.size)}</option>`; }).join("")}</select></label></div>`,
          );
          $("#addBtn").disabled = true;
          $("#sizeSelect").onchange = () => {
            V = P.variants.find((variant) => variant.id == $("#sizeSelect").value) || null;
            $("#addBtn").disabled = !V || Number(V.price) <= 0;
          };
          if (P.variants.length === 1) {
            $("#sizeSelect").value = String(P.variants[0].id);
            $("#sizeSelect").dispatchEvent(new Event("change"));
          }
          $("#addBtn").onclick = async () => {
            if (!V) return;
            const requestedQty = Math.max(1, parseInt($("#qty").value) || 1);
            const q = Math.min(MAX_ORDER_QTY, requestedQty);
            const withinLimit = Cart.add(V.id, V.id, q) && requestedQty <= MAX_ORDER_QTY;
            toast(withinLimit
              ? P.name + " (" + V.size + ") added to request cart"
              : "Maximum 1,000 units per product");
          };
          const rel = (await api("api/products.php?limit=5")).products
            .filter((x) => x.id !== P.id)
            .slice(0, 4);
          if (rel.length) {
            $("#rel").innerHTML = rel.map(productCard).join("");
            $("#relWrap").hidden = false;
          }
        } catch {
          $("#pdp").innerHTML =
            '<p class="err-page">We could not find that product. <a href="dashboard.php">Back to products</a></p>';
        }
      })();
    }
  }

  {
    if (document.querySelector("#quotations")) {
      const host = $("#quotations");
      const FILTERS = [
        ["all", "All"],
        ["pending", "Needs review"],
        ["expired", "Expired"],
        ["Approved", "Accepted"],
        ["Rejected", "Declined"],
      ];
      const matches = (quotation, filter) => {
        if (filter === "all") return true;
        if (filter === "pending") return quotation.customer_status === "Pending" && !isExpiredQuotation(quotation);
        if (filter === "expired") return isExpiredQuotation(quotation);
        return quotation.customer_status === filter;
      };
      let filter = FILTERS.some(([key]) => key === param("filter"))
        ? param("filter")
        : "all";
      let quotations = [];
      let orders = [];

      function draw() {
        $("#qTabs").innerHTML = FILTERS.map(([key, label]) => {
          const count = quotations.filter((q) => matches(q, key)).length;
          return `<button type="button" class="chip ${filter === key ? "on" : ""}" data-filter="${key}" role="tab" aria-selected="${filter === key}">${label} (${count})</button>`;
        }).join("");
        const shown = quotations.filter((q) => matches(q, filter));
        host.innerHTML = shown.length
          ? shown
              .map((quotation) =>
                quotationHtml(quotation, {
                  order: orders.find(
                    (order) => order.quotation_no === quotation.quotation_no,
                  ),
                }),
              )
              .join("")
          : filter === "pending"
            ? '<div class="empty-state"><h2>You’re all caught up</h2><p class="muted">There are no quotations waiting for your decision.</p></div>'
            : '<div class="empty-state"><h2>No quotations here yet</h2><p class="muted">When a price is confirmed for your request, the quotation will appear here.</p><a class="btn" href="requests.php">Check my requests</a></div>';
      }

      async function load() {
        try {
          await requireLogin();
          const [q, o] = await Promise.all([
            api("api/quotations.php"),
            api("api/orders.php").catch(() => ({ orders: [] })),
          ]);
          quotations = q.quotations || [];
          orders = o.orders || [];
          draw();
        } catch (error) {
          host.innerHTML = `<p class="error" role="alert">${esc(error.message)}</p>`;
        }
      }

      $("#qTabs").onclick = (event) => {
        const tab = event.target.closest("[data-filter]");
        if (!tab) return;
        filter = tab.dataset.filter;
        history.replaceState(
          null,
          "",
          "quotations.php" + (filter === "all" ? "" : "?filter=" + filter),
        );
        draw();
      };
      bindQuotationDecisions(host, load);
      load();
    }
  }

  {
    if (document.querySelector("#requests")) {
      const requestsHost = $("#requests");

      async function loadRequests() {
        requestsHost.innerHTML = '<p class="muted">Loading your requests…</p>';
        try {
          await requireLogin();
          const { orders } = await api("api/orders.php");
          requestsHost.innerHTML = orders.length
            ? orders.map(orderHtml).join("")
          : '<div class="empty-state"><h2>No requests yet</h2><p class="muted">Your submitted product requests will appear here.</p><a class="btn" href="ordering.php">Browse products</a></div>';
        } catch (error) {
          requestsHost.innerHTML = `<p class="error" role="alert">${esc(error.message)}</p>`;
        }
      }

      if (requestsHost) loadRequests();
    }
  }

  {
    if (document.querySelector("#tracker")) {
      const host = $("#tracker");
      const STEPS = [
        "Request sent",
        "Quotation ready",
        "Your decision",
        "Purchase order",
        "Sales order",
        "Sales approval",
        "Delivery",
        "Complete",
      ];

      function stages(order, quotation) {
        const rejected =
          quotation?.customer_status === "Rejected" ||
          /reject|declin/i.test(order.status || "");
        const accepted =
          quotation?.customer_status === "Approved" ||
          !!order.po_no ||
          !!order.order_no;
        const complete =
          order.delivery_status === "Delivered" ||
          order.order_status === "Completed";
        const details = [
          order.request_date,
          rejected ? "Previous quotation declined" : order.quotation_no,
          rejected ? "Declined; waiting for a revised quotation" : accepted ? "Accepted" : "",
          order.po_no
            ? `${order.po_no}${order.po_status ? ` · ${order.po_status}` : ""}`
            : "",
          order.order_no
            ? `${order.order_no}${order.order_status ? ` · ${order.order_status}` : ""}`
            : "",
          order.order_admin_status === "Approved"
            ? "Approved"
            : order.order_admin_status === "Rejected"
              ? "Rejected"
              : order.order_no
                ? "Awaiting approval"
                : "",
          order.delivery_no
            ? `${order.delivery_no}${order.delivery_status ? ` · ${order.delivery_status}` : ""}`
            : "",
          order.invoice_no
            ? `${order.invoice_no}${order.invoice_status ? ` · ${order.invoice_status}` : ""}`
            : order.payment_status
              ? `Payment ${order.payment_status}`
              : "",
        ];
        const done = [
          true,
          !!order.quotation_no && !rejected,
          accepted,
          !!order.po_no,
          !!order.order_no,
          order.order_admin_status === "Approved",
          complete,
          complete,
        ];
        let currentIndex = done.findIndex((stepDone, index) => {
          if (stepDone) return false;
          if (index === 2 && rejected) return false;
          if (
            index === 5 &&
            order.order_admin_status === "Rejected"
          )
            return false;
          if (
            index >= 6 &&
            order.order_admin_status === "Rejected"
          )
            return false;
          if (index === 6 && !order.order_no) return false;
          if (index === 7 && !order.order_no) return false;
          return true;
        });
        if (currentIndex < 0 && rejected) currentIndex = 1;

        return STEPS.map((label, index) => {
          let state = done[index] ? "done" : "todo";
          if (index === 2 && rejected) state = "declined";
          else if (
            index === 5 &&
            order.order_admin_status === "Rejected"
          )
            state = "declined";
          else if (index === currentIndex) state = "current";
          return `<li class="step ${state}">${label}${details[index] ? `<small>${esc(details[index])}</small>` : ""}</li>`;
        }).join("");
      }

      (async () => {
        try {
          await requireLogin();
          const [o, q] = await Promise.all([
            api("api/orders.php"),
            api("api/quotations.php").catch(() => ({ quotations: [] })),
          ]);
          const orders = o.orders || [];
          const quotations = q.quotations || [];
          host.innerHTML = orders.length
            ? orders
                .map((order) => {
                  const quotation = quotations.find(
                    (item) => item.quotation_no === order.quotation_no,
                  );
                  const product = order.items?.[0]?.product_name || order.product_code || "Product";
                  return `<article class="order" id="${esc(order.request_no)}"><header><div><strong>${esc(order.request_no)}</strong><br><span class="muted">${esc(product)} · Qty ${esc(order.quantity ?? "")}</span></div><span class="status">${esc(order.status || "Pending")}</span></header><ol class="steps">${stages(order, quotation)}</ol></article>`;
                })
                .join("")
            : '<div class="empty-state"><h2>Nothing to track yet</h2><p class="muted">Once you submit a request, its progress will show up here.</p><a class="btn" href="ordering.php">Browse products</a></div>';
          if (location.hash) {
            const target = document.getElementById(decodeURIComponent(location.hash.slice(1)));
            if (target) target.scrollIntoView({ block: "center" });
          }
        } catch (error) {
          host.innerHTML = `<p class="error" role="alert">${esc(error.message)}</p>`;
        }
      })();
    }
  }

  {
    if (document.querySelector("#ok")) {
      (async () => {
        await requireLogin();
        try {
          const result = await api(
            "api/orders.php?number=" + encodeURIComponent(param("o")),
          );
          const order = result.orders[0];
          if (!order) throw new Error("Request not found");
          $("#ok").innerHTML =
            `<h1>Request received</h1><p class="muted">Request <strong>${esc(order.request_no || order.order_number)}</strong> is now in your account. You can follow its progress in My requests. We’ll show the confirmed price here when it’s ready.</p><div style="text-align:left">${orderHtml(order)}</div><a class="btn" href="requests.php">View my requests</a> <a class="btn dark" href="ordering.php">Continue shopping</a>`;
        } catch (error) {
          $("#ok").innerHTML =
            `<p class="error">${esc(error.message)}</p><a class="btn" href="requests.php">My requests</a>`;
        }
      })();
    }
  }
})();
