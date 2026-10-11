/* Metro Valet Parking — public site interactions (vanilla JS, no dependencies) */
(function () {
  'use strict';

  /* Mobile nav toggle */
  var toggle = document.getElementById('navToggle');
  if (toggle) {
    toggle.addEventListener('click', function () {
      var open = document.body.classList.toggle('nav-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    // Close the drawer when a link is tapped
    document.querySelectorAll('.nav-links a').forEach(function (a) {
      a.addEventListener('click', function () {
        document.body.classList.remove('nav-open');
        toggle.setAttribute('aria-expanded', 'false');
      });
    });
  }

  /* Smooth scroll for in-page anchors */
  document.querySelectorAll('a[href^="#"]').forEach(function (a) {
    var id = a.getAttribute('href');
    if (id.length < 2) return;
    var target = document.querySelector(id);
    if (!target) return;
    a.addEventListener('click', function (ev) {
      ev.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      history.replaceState(null, '', id);
    });
  });

  /* Reveal on scroll */
  var revealEls = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window && revealEls.length) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12 });
    revealEls.forEach(function (el) { io.observe(el); });
  } else {
    revealEls.forEach(function (el) { el.classList.add('visible'); });
  }

  /* FAQ accordion */
  document.querySelectorAll('.faq-item').forEach(function (item) {
    var q = item.querySelector('.faq-q');
    if (!q) return;
    q.addEventListener('click', function () {
      var isOpen = item.classList.contains('open');
      document.querySelectorAll('.faq-item.open').forEach(function (o) {
        o.classList.remove('open');
        o.querySelector('.faq-q').setAttribute('aria-expanded', 'false');
      });
      if (!isOpen) {
        item.classList.add('open');
        q.setAttribute('aria-expanded', 'true');
      }
    });
  });

  /* Individual / Company toggle on request-monthly.php */
  var tabs = document.querySelectorAll('[data-form-toggle]');
  var typeInput = document.getElementById('requestType');
  if (tabs.length && typeInput) {
    var panels = {
      individual: document.getElementById('panelIndividual'),
      company: document.getElementById('panelCompany')
    };
    function setType(t) {
      typeInput.value = t;
      tabs.forEach(function (tab) {
        tab.classList.toggle('active', tab.getAttribute('data-form-toggle') === t);
      });
      Object.keys(panels).forEach(function (key) {
        var p = panels[key];
        if (!p) return;
        var show = key === t;
        p.hidden = !show;
        // Disabled fields are skipped by both validation and submission,
        // so only the visible panel's fields are sent.
        p.querySelectorAll('input,select,textarea').forEach(function (f) {
          f.disabled = !show;
        });
      });
    }
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () { setType(tab.getAttribute('data-form-toggle')); });
    });
    // Respect server-side state after a validation error
    setType(typeInput.value === 'company' ? 'company' : 'individual');
  }

  /* Prevent double submission on public forms */
  document.querySelectorAll('form[data-once]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var btn = form.querySelector('button[type="submit"]');
      if (!btn || btn.disabled) return;
      // Let native validation run first
      if (!form.checkValidity()) return;
      btn.disabled = true;
      btn.dataset.label = btn.textContent;
      btn.textContent = 'Submitting…';
    });
  });
})();
