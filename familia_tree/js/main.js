(function () {
  'use strict';

  /* ---------- Плавная прокрутка колесом / тачпадом (Lenis) ---------- */
  // Без библиотеки или при «уменьшить движение» остаётся обычная прокрутка + CSS scroll-behavior для якорей.
  if (window.Lenis && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    var headerEl = document.querySelector('.header--sticky');
    var lenis = new Lenis({
      lerp: 0.1,                 // чем меньше, тем «мягче» и дольше доезжает
      autoRaf: true,
      anchors: {                 // ссылки #about, #contact и т.п. — тоже плавно, с учётом закреплённой шапки и отступа над ней
        offset: -((headerEl ? headerEl.getBoundingClientRect().bottom : 0) + 16)
      }
    });
    window.lenis = lenis;
  }

  /* ---------- Мобильное меню (в каждой шапке своё) ---------- */
  var burgers = document.querySelectorAll('.header__burger');

  function closeAll(except) {
    burgers.forEach(function (b) {
      var m = document.getElementById(b.getAttribute('aria-controls'));
      if (!m || m === except || m.hidden) return;
      m.hidden = true;
      b.setAttribute('aria-expanded', 'false');
      b.setAttribute('aria-label', 'Открыть меню');
    });
  }

  burgers.forEach(function (burger) {
    var menu = document.getElementById(burger.getAttribute('aria-controls'));
    if (!menu) return;
    burger.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = menu.hidden;
      closeAll(menu);
      menu.hidden = !open;
      burger.setAttribute('aria-expanded', String(open));
      burger.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
    });
    menu.addEventListener('click', function (e) {
      if (e.target.closest('a')) closeAll();
    });
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest('.mobile-menu')) closeAll();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAll();
  });
  window.addEventListener('resize', function () {
    if (window.innerWidth >= 1024) closeAll();
  });

  /* ---------- Бегущая лента: едет сама, можно тянуть мышкой / пальцем ---------- */
  var marquee = document.querySelector('.marquee');
  if (marquee) {
    var track = marquee.querySelector('.marquee__track');
    var cards = track.children;
    var half = cards.length / 2;                 // вторая половина — копия набора
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    var period = 0, autoV = 0, x = 0, v = 0;
    var dragging = false, lastX = 0, lastT = 0, dragV = 0, prevT = performance.now();

    var measure = function () {
      period = cards[half].offsetLeft - cards[0].offsetLeft;
      var duration = parseFloat(getComputedStyle(marquee).getPropertyValue('--duration')) || 102;
      autoV = reduceMotion.matches ? 0 : -period / duration;   // px/с, минус — влево
    };

    var frame = function (t) {
      var dt = Math.min((t - prevT) / 1000, 0.05);
      prevT = t;
      if (!dragging) {
        v += (autoV - v) * Math.min(1, dt * 1.5);  // после броска плавно возвращаемся к обычной скорости
        x += v * dt;
      }
      if (period > 0) x = ((x % period) - period) % period;  // держим x в (-period, 0] — бесшовный круг
      track.style.transform = 'translate3d(' + x + 'px, 0, 0)';
      requestAnimationFrame(frame);
    };

    marquee.addEventListener('pointerdown', function (e) {
      if (e.button !== 0) return;
      dragging = true;
      lastX = e.clientX;
      lastT = e.timeStamp;
      dragV = 0;
      marquee.setPointerCapture(e.pointerId);
      marquee.classList.add('is-dragging');
    });
    marquee.addEventListener('pointermove', function (e) {
      if (!dragging) return;
      var dx = e.clientX - lastX;
      var dt = (e.timeStamp - lastT) / 1000;
      x += dx;
      if (dt > 0) dragV = dragV * 0.5 + (dx / dt) * 0.5;
      lastX = e.clientX;
      lastT = e.timeStamp;
    });
    var release = function (e) {
      if (!dragging) return;
      dragging = false;
      marquee.classList.remove('is-dragging');
      if (e.timeStamp - lastT > 100) dragV = 0;  // палец остановился перед отпусканием — без броска
      v = Math.max(-4000, Math.min(4000, dragV));
    };
    marquee.addEventListener('pointerup', release);
    marquee.addEventListener('pointercancel', release);

    measure();
    v = autoV;
    window.addEventListener('resize', measure);
    marquee.classList.add('marquee--js');
    requestAnimationFrame(frame);
  }

  /* ---------- Появление при прокрутке: заголовки — по буквам, блоки — мягко снизу ---------- */
  var motionOK = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (motionOK && 'IntersectionObserver' in window) {
    // разбиваем заголовок на слова (не рвутся при переносе) и буквы; <br> и вложенные span сохраняются
    var splitLetters = function (el) {
      var label = el.textContent.replace(/\s+/g, ' ').trim();
      var i = 0;
      var walk = function (node) {
        Array.prototype.slice.call(node.childNodes).forEach(function (child) {
          if (child.nodeType === 3) {
            var frag = document.createDocumentFragment();
            child.textContent.split(/([ \t\n\r]+)/).forEach(function (part) {
              if (!part) return;
              if (/^[ \t\n\r]+$/.test(part)) { frag.appendChild(document.createTextNode(' ')); return; }
              var word = document.createElement('span');
              word.className = 'lw';
              Array.from(part).forEach(function (ch) {
                var c = document.createElement('span');
                c.className = 'lc';
                c.textContent = ch;
                c.style.setProperty('--i', i++);
                word.appendChild(c);
              });
              frag.appendChild(word);
            });
            node.replaceChild(frag, child);
          } else if (child.nodeType === 1 && child.tagName !== 'BR') {
            walk(child);
          }
        });
      };
      walk(el);
      // для экранных дикторов — обычный текст, анимированные буквы от них скрыты
      var visual = document.createElement('span');
      visual.setAttribute('aria-hidden', 'true');
      while (el.firstChild) visual.appendChild(el.firstChild);
      var sr = document.createElement('span');
      sr.className = 'visually-hidden';
      sr.textContent = label;
      el.appendChild(sr);
      el.appendChild(visual);
      el.style.setProperty('--step', Math.min(32, 1500 / Math.max(i, 1)).toFixed(1) + 'ms');
      el.classList.add('letters');
    };

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        e.target.classList.add('is-in');
        io.unobserve(e.target);
      });
    }, { rootMargin: '0px 0px -12% 0px' });

    document.querySelectorAll('.h2, .about .h3').forEach(function (h) {
      if (h.closest('.directions')) return;       // второй блок («Направления и услуги») — текст без анимации
      splitLetters(h);
      io.observe(h);
    });
    document.querySelectorAll(
      '.section-head .link-more, .directions__list, .about__text, .about .dots, ' +
      '.reviews__list, .blog__grid, .contact__text, .contact .socials, .contact .form'
    ).forEach(function (el) {
      el.style.transition = 'none';               // прячем сразу, без обратного «затухания» при загрузке
      el.classList.add('reveal');
      io.observe(el);
    });
    void document.body.offsetHeight;
    document.querySelectorAll('.reveal').forEach(function (el) { el.style.transition = ''; });
  }

  /* ---------- Блог: последние посты из группы ВКонтакте ---------- */
  var blog = document.querySelector('[data-vk-blog]');
  if (blog && window.fetch && /^https?:$/.test(location.protocol)) {
    var blogCards = blog.querySelectorAll('.post');
    blog.classList.add('is-loading');

    var fillCard = function (card, post, isMain) {
      var img = card.querySelector('.post__img');
      var title = card.querySelector('.post__title');
      var desc = card.querySelector('.post__desc');
      var link = card.querySelector('.post__link');
      img.src = isMain ? post.image : (post.image_small || post.image);
      img.alt = post.title;
      img.referrerPolicy = 'no-referrer';
      title.textContent = post.title;
      desc.textContent = post.text || '';
      desc.hidden = !post.text;
      link.href = post.url;
      link.target = '_blank';
      link.rel = 'noopener';
    };

    fetch(blog.getAttribute('data-vk-blog'), { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function (data) {
        var posts = (data && data.posts) || [];
        if (!posts.length) return;                 // постов нет — оставляем заготовки
        blogCards.forEach(function (card, i) {
          if (posts[i]) fillCard(card, posts[i], i === 0);
          else card.hidden = true;                 // постов меньше, чем карточек
        });
      })
      .catch(function (err) {
        if (window.console) console.warn('Блог ВК: посты не загрузились (' + err + '), показаны заготовки');
      })
      .then(function () { blog.classList.remove('is-loading'); });
  }

  /* ---------- Форма ---------- */
  var form = document.querySelector('.form');
  if (form) {
    var contact = form.querySelector('[name="contact"]');
    var agree = form.querySelector('[name="agree"]');
    var note = form.querySelector('.form__note');

    [contact, agree].forEach(function (el) {
      el.addEventListener('input', function () { el.classList.remove('is-invalid'); });
      el.addEventListener('change', function () { el.classList.remove('is-invalid'); });
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var ok = true;
      if (!contact.value.trim()) { contact.classList.add('is-invalid'); ok = false; }
      if (!agree.checked) { agree.classList.add('is-invalid'); ok = false; }

      if (!ok) {
        note.textContent = !contact.value.trim()
          ? 'Укажите телефон, почту или мессенджер.'
          : 'Подтвердите согласие с политикой конфиденциальности.';
        return;
      }

      // Здесь подключается отправка на сервер (fetch / CRM / почта).
      note.textContent = 'Спасибо! Мы свяжемся с вами в ближайшее время.';
      form.reset();
    });
  }
})();
