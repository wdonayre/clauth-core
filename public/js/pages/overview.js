// Overview: greet by the viewer's own clock (the server runs on UTC).
(function () {
  var h = document.getElementById('greeting');
  if (!h || !h.dataset.name) return;
  var hour = new Date().getHours();
  var part = hour < 12 ? 'Good morning' : (hour < 18 ? 'Good afternoon' : 'Good evening');
  h.textContent = part + ', ' + h.dataset.name;
})();
