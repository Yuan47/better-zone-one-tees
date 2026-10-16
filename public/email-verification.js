(() => {
  const button = document.getElementById('verification-resend');
  if (!button) return;
  const until = Date.parse(button.dataset.resendAt || '');
  const label = button.dataset.initialLabel || 'Send a new code';
  const update = () => {
    const seconds = Number.isFinite(until) ? Math.max(0, Math.ceil((until - Date.now()) / 1000)) : 0;
    button.disabled = seconds > 0;
    button.textContent = seconds > 0
      ? (seconds >= 60 ? 'Resend in ' + Math.ceil(seconds / 60) + ' min' : 'Resend in ' + seconds + 's')
      : label;
    return seconds > 0;
  };
  if (update()) {
    const timer = window.setInterval(() => {
      if (!update()) window.clearInterval(timer);
    }, 1000);
  }
})();
