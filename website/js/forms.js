var DV_API_URL = 'https://deverhuizing.nl';
var DV_API_MODE = 'wordpress';

(function() {
  function getEndpointUrl(endpoint) {
    if (DV_API_MODE === 'wordpress') {
      return DV_API_URL + '/wp-json/deverhuizing/v1/' + endpoint;
    }
    var map = { quote: 'quote-requests', callback: 'callback-requests', contact: 'contact-messages' };
    return DV_API_URL + '/api/' + (map[endpoint] || endpoint);
  }

  function showMessage(form, message, isError) {
    var existing = form.querySelector('.dv-form-message');
    if (existing) existing.remove();
    var div = document.createElement('div');
    div.className = 'dv-form-message';
    div.style.cssText = 'padding:12px 16px;border-radius:8px;margin-top:12px;font-size:14px;font-weight:500;' +
      (isError ? 'background:#fef2f2;color:#dc2626;border:1px solid #fecaca;' : 'background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;');
    div.textContent = message;
    form.appendChild(div);
    if (!isError) {
      setTimeout(function() { if (div.parentNode) div.remove(); }, 5000);
    }
  }

  function setLoading(button, loading) {
    if (!button) return;
    if (loading) {
      button.dataset.originalText = button.textContent;
      button.textContent = 'Verzenden...';
      button.disabled = true;
      button.style.opacity = '0.7';
    } else {
      button.textContent = button.dataset.originalText || 'Verstuur';
      button.disabled = false;
      button.style.opacity = '1';
    }
  }

  function getVal(form, name) {
    var el = form.querySelector('[name="' + name + '"]');
    return el ? el.value.trim() : '';
  }

  function submitForm(endpoint, data, form, button) {
    setLoading(button, true);
    fetch(getEndpointUrl(endpoint), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    })
    .then(function(r) {
      if (!r.ok) {
        return r.json().catch(function() { return { message: 'Server fout (' + r.status + ')' }; }).then(function(err) {
          throw new Error(err.message || 'Er ging iets mis.');
        });
      }
      return r.json();
    })
    .then(function(result) {
      setLoading(button, false);
      if (result.success) {
        showMessage(form, 'Bedankt! Uw aanvraag is succesvol verzonden. We nemen zo snel mogelijk contact met u op.', false);
        form.reset();
      } else {
        showMessage(form, result.message || 'Er ging iets mis. Probeer het opnieuw.', true);
      }
    })
    .catch(function(err) {
      setLoading(button, false);
      showMessage(form, err.message || 'Er ging iets mis met de verbinding. Probeer het opnieuw of bel ons op 070 7070341.', true);
    });
  }

  document.addEventListener('DOMContentLoaded', function() {

    var quickForm = document.querySelector('[data-testid="form-quick-quote"]');
    if (quickForm) {
      quickForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = quickForm.querySelector('[data-testid="button-quick-submit"]') || quickForm.querySelector('button[type="submit"]') || quickForm.querySelector('button');
        var data = {
          firstName: getVal(quickForm, 'firstName'),
          email: getVal(quickForm, 'email'),
          phone: getVal(quickForm, 'phone'),
          moveType: getVal(quickForm, 'moveType'),
          moveDate: getVal(quickForm, 'preferredDate')
        };
        if (!data.firstName || !data.phone) {
          showMessage(quickForm, 'Vul a.u.b. uw naam en telefoonnummer in.', true);
          return;
        }
        submitForm('quote', data, quickForm, btn);
      });
    }

    var allForms = document.querySelectorAll('form');
    allForms.forEach(function(form) {
      if (form === quickForm) return;

      var callbackFirstName = form.querySelector('[data-testid="input-callback-firstname"]');
      if (callbackFirstName) {
        form.addEventListener('submit', function(e) {
          e.preventDefault();
          var btn = form.querySelector('[data-testid="button-callback-submit"]') || form.querySelector('button[type="submit"]') || form.querySelector('button');
          var data = {
            firstName: getVal(form, 'firstName'),
            lastName: getVal(form, 'lastName'),
            phone: getVal(form, 'phone'),
            email: getVal(form, 'email'),
            preferredTime: getVal(form, 'requestType')
          };
          if (!data.firstName || !data.phone) {
            showMessage(form, 'Vul a.u.b. uw naam en telefoonnummer in.', true);
            return;
          }
          submitForm('callback', data, form, btn);
        });
        return;
      }

      var quoteFirstName = form.querySelector('[data-testid="input-quote-firstname"]');
      if (quoteFirstName) {
        form.addEventListener('submit', function(e) {
          e.preventDefault();
          var btn = form.querySelector('[data-testid="button-quote-submit"]') || form.querySelector('button[type="submit"]') || form.querySelector('button');
          var data = {
            firstName: getVal(form, 'firstName'),
            lastName: getVal(form, 'lastName'),
            email: getVal(form, 'email'),
            phone: getVal(form, 'phone'),
            moveFromAddress: getVal(form, 'moveFromAddress'),
            moveFromPostcode: getVal(form, 'moveFromPostcode'),
            moveFromCity: getVal(form, 'moveFromCity'),
            moveToAddress: getVal(form, 'moveToAddress'),
            moveToPostcode: getVal(form, 'moveToPostcode'),
            moveToCity: getVal(form, 'moveToCity'),
            moveType: getVal(form, 'moveType'),
            moveDate: getVal(form, 'moveDate'),
            additionalNotes: getVal(form, 'additionalNotes')
          };
          if (!data.firstName || !data.email || !data.phone) {
            showMessage(form, 'Vul a.u.b. uw naam, e-mail en telefoonnummer in.', true);
            return;
          }
          submitForm('quote', data, form, btn);
        });
        return;
      }

      var contactName = form.querySelector('[data-testid="input-contact-name"]');
      if (contactName) {
        form.addEventListener('submit', function(e) {
          e.preventDefault();
          var btn = form.querySelector('[data-testid="button-contact-submit"]') || form.querySelector('button[type="submit"]') || form.querySelector('button');
          var data = {
            name: getVal(form, 'name'),
            email: getVal(form, 'email'),
            phone: getVal(form, 'phone'),
            subject: getVal(form, 'subject'),
            message: getVal(form, 'message')
          };
          if (!data.name || !data.email || !data.message) {
            showMessage(form, 'Vul a.u.b. uw naam, e-mail en bericht in.', true);
            return;
          }
          submitForm('contact', data, form, btn);
        });
        return;
      }
    });
  });
})();
