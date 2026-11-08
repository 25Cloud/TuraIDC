// 控制台面板本地运行时：兼容旧版 SweetAlert，并把跨源弹窗请求交给父页面。
(function () {
  if (window.Swal && typeof window.Swal.fire === 'function' && !window.__turaSwalCompat) {
    window.__turaSwalCompat = true;
    var rawFire = window.Swal.fire.bind(window.Swal);
    var icons = ['success', 'error', 'warning', 'info', 'question'];
    window.Swal.fire = function () {
      var args = Array.prototype.slice.call(arguments);
      if (args.length === 1 && args[0] && typeof args[0] === 'object' && args[0].type) {
        if (!args[0].icon && icons.indexOf(args[0].type) !== -1) {
          args[0].icon = args[0].type;
        }
        delete args[0].type;
      }
      return rawFire.apply(null, args);
    };
  }

  if (window.__turaPopupBridge) {
    return;
  }
  window.__turaPopupBridge = true;

  var eventName = 'tura:open-url';
  var nativeOpen = window.open.bind(window);
  var sequence = 0;

  function isHttpUrl(value) {
    try {
      var protocol = new URL(String(value), window.location.href).protocol;
      return protocol === 'http:' || protocol === 'https:';
    } catch (error) {
      return false;
    }
  }

  window.open = function (url, target, features) {
    var href = String(url == null ? '' : url);
    if (!href || !isHttpUrl(href)) {
      return nativeOpen(url, target, features);
    }
    if (target === '_top' || target === '_parent' || target === 'top' || target === 'parent') {
      return nativeOpen(url, target, features);
    }

    try {
      if (window.parent && window.parent !== window) {
        sequence += 1;
        var id = 'tura-open-' + sequence;
        var acknowledged = false;
        var timer = window.setTimeout(function () {
          if (!acknowledged) {
            nativeOpen(href, '_blank', 'noopener,noreferrer');
          }
        }, 1200);

        window.addEventListener('message', function handler(message) {
          if (acknowledged || !message.data || message.data.type !== eventName + ':ack' || message.data.id !== id) {
            return;
          }
          acknowledged = true;
          window.clearTimeout(timer);
          window.removeEventListener('message', handler);
        });

        window.parent.postMessage({ type: eventName, id: id, url: href }, '*');
        return null;
      }
    } catch (error) {
      // 父页面未监听时由下方原生行为兜底。
    }

    return nativeOpen(url, target, features);
  };
})();
