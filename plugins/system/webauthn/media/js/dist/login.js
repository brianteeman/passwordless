"use strict";

function _objectSpread(target) { for (var i = 1; i < arguments.length; i++) { var source = arguments[i] != null ? arguments[i] : {}; var ownKeys = Object.keys(source); if (typeof Object.getOwnPropertySymbols === 'function') { ownKeys = ownKeys.concat(Object.getOwnPropertySymbols(source).filter(function (sym) { return Object.getOwnPropertyDescriptor(source, sym).enumerable; })); } ownKeys.forEach(function (key) { _defineProperty(target, key, source[key]); }); } return target; }

function _defineProperty(obj, key, value) { if (key in obj) { Object.defineProperty(obj, key, { value: value, enumerable: true, configurable: true, writable: true }); } else { obj[key] = value; } return obj; }

function _toConsumableArray(arr) { return _arrayWithoutHoles(arr) || _iterableToArray(arr) || _nonIterableSpread(); }

function _nonIterableSpread() { throw new TypeError("Invalid attempt to spread non-iterable instance"); }

function _iterableToArray(iter) { if (Symbol.iterator in Object(iter) || Object.prototype.toString.call(iter) === "[object Arguments]") return Array.from(iter); }

function _arrayWithoutHoles(arr) { if (Array.isArray(arr)) { for (var i = 0, arr2 = new Array(arr.length); i < arr.length; i++) { arr2[i] = arr[i]; } return arr2; } }

/**
 * @package   AkeebaPasswordlessLogin
 * @copyright Copyright (c)2018-2019 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */
// TODO Write the akeeba_passwordless_login() function
// See https://github.com/web-auth/webauthn-framework/blob/v1.0/doc/webauthn/PublicKeyCredentialRequest.md

/**
 * Finds the first field matching a selector inside a form
 *
 * @param   {HTMLFormElement}  elForm         The FORM element
 * @param   {String}           fieldSelector  The CSS selector to locate the field
 *
 * @returns {Element|null}  NULL when no element is found
 */
function akeeba_passwordless_findField(elForm, fieldSelector) {
  var elInputs = elForm.querySelectorAll(fieldSelector);

  if (!elInputs.length) {
    return null;
  }

  return elInputs[0];
}
/**
 * Walks the DOM outwards (towards the parents) to find the form innerElement is located in. Then it looks inside the
 * form for the first element that matches the fieldSelector CSS selector.
 *
 * @param   {Element}  innerElement   The innerElement that's inside or adjacent to the form.
 * @param   {String}   fieldSelector  The CSS selector to locate the field
 *
 * @returns {null|Element}  NULL when no element is found
 */


function akeeba_passwordless_lookInParentElementsForField(innerElement, fieldSelector) {
  var elElement = innerElement.parentElement;
  var elInput = null;

  while (true) {
    if (elElement === undefined) {
      return null;
    }

    if (elElement.nodeName === "FORM") {
      elInput = akeeba_passwordless_findField(elElement, fieldSelector);

      if (elInput !== null) {
        return elInput;
      }

      break;
    }

    var elForms = elElement.querySelectorAll("form");

    if (elForms.length) {
      for (var i = 0; i < elForms.length; i++) {
        elInput = akeeba_passwordless_findField(elForms[i], fieldSelector);

        if (elInput !== null) {
          return elInput;
        }
      }

      break;
    }

    if (!elElement.parentElement) {
      break;
    }

    elElement = elElement.parentElement;
  }

  return null;
}
/**
 * Initialize the passwordless login, going through the server to get the registered certificates for the user.
 *
 * @param   {Element}  that          The button which was clicked
 * @param   {string}   callback_url  The URL we will use to post back to the server. Must include the anti-CSRF token.
 *
 * @returns {boolean}  Always FALSE to prevent BUTTON elements from reloading the page.
 */


function akeeba_passwordless_login(that, callback_url) {
  // Get the username
  var elUsername = akeeba_passwordless_lookInParentElementsForField(that, "input[name=username]");
  var elReturn = akeeba_passwordless_lookInParentElementsForField(that, "input[name=return]");

  if (elUsername === null) {
    alert(Joomla.JText._("PLG_SYSTEM_WEBAUTHN_ERR_CANNOT_FIND_USERNAME"));
    return false;
  }

  var username = elUsername.value;
  var returnUrl = elReturn ? elReturn.value : null; // No username? We cannot proceed. We need a username to find the acceptable public keys :(

  if (username === "") {
    alert(Joomla.JText._("PLG_SYSTEM_WEBAUTHN_ERR_EMPTY_USERNAME"));
    return false;
  } // Get the Public Key Credential Request Options (challenge and acceptable public keys)


  var postBackData = {
    "option": "com_ajax",
    "group": "system",
    "plugin": "webauthn",
    "format": "raw",
    "akaction": "challenge",
    "encoding": "raw",
    "username": username,
    "returnUrl": returnUrl
  };
  window.jQuery.ajax({
    type: "POST",
    url: callback_url,
    data: postBackData,
    dataType: "json"
  }).done(function (jsonData) {
    akeeba_passwordless_handle_login_challenge(jsonData, callback_url);
  }).fail(function (error) {
    akeeba_passwordless_handle_login_error(error.status + " " + error.statusText);
  });
  return false;
}
/**
 * Handles the browser response for the user interaction with the authenticator. Redirects to an internal page which
 * handles the login server-side.
 *
 * @param {  Object}  publicKey     Public key request options, returned from the server
 * @param   {String}  callback_url  The URL we will use to post back to the server. Must include the anti-CSRF token.
 */


function akeeba_passwordless_handle_login_challenge(publicKey, callback_url) {
  function arrayToBase64String(a) {
    return btoa(String.fromCharCode.apply(String, _toConsumableArray(a)));
  }

  if (!publicKey.challenge) {
    akeeba_passwordless_handle_login_error(Joomla.JText._('PLG_SYSTEM_WEBAUTHN_ERR_INVALID_USERNAME'));
    return;
  }

  publicKey.challenge = Uint8Array.from(window.atob(publicKey.challenge), function (c) {
    return c.charCodeAt(0);
  });
  publicKey.allowCredentials = publicKey.allowCredentials.map(function (data) {
    return _objectSpread({}, data, {
      "id": Uint8Array.from(atob(data.id), function (c) {
        return c.charCodeAt(0);
      })
    });
  });
  navigator.credentials.get({
    publicKey: publicKey
  }).then(function (data) {
    var publicKeyCredential = {
      id: data.id,
      type: data.type,
      rawId: arrayToBase64String(new Uint8Array(data.rawId)),
      response: {
        authenticatorData: arrayToBase64String(new Uint8Array(data.response.authenticatorData)),
        clientDataJSON: arrayToBase64String(new Uint8Array(data.response.clientDataJSON)),
        signature: arrayToBase64String(new Uint8Array(data.response.signature)),
        userHandle: data.response.userHandle ? arrayToBase64String(new Uint8Array(data.response.userHandle)) : null
      }
    };
    window.location = callback_url + '&option=com_ajax&group=system&plugin=webauthn&format=raw&akaction=login&encoding=redirect&data=' + btoa(JSON.stringify(publicKeyCredential));
  }, function (error) {
    // Example: timeout, interaction refused...
    console.log(error);
    akeeba_passwordless_handle_login_error(error);
  });
}
/**
 * A simple error handler.
 *
 * @param   {String}  message
 */


function akeeba_passwordless_handle_login_error(message) {
  alert(message);
  console.log(message);
}
/**
 * Moves the passwordless login button next to the existing Login button in the login module, if possible. This is not a
 * guaranteed success! We will *try* to find a button that looks like the login action button. If the developer of the
 * module or the site integrator doing template overrides didn't bother including some useful information to help us
 * identify it we're probably going to fail hard.
 *
 * @param   {Element}  elPasswordlessLoginButton  The login button to move.
 * @param   {Array}    possibleSelectors          The CSS selectors to use for moving the button.
 */


function akeeba_passwordless_login_move_button(elPasswordlessLoginButton, possibleSelectors) {
  if (elPasswordlessLoginButton === null || elPasswordlessLoginButton === undefined) {
    return;
  }

  var elLoginBtn = null;

  for (var i = 0; i < possibleSelectors.length; i++) {
    var selector = possibleSelectors[i];
    elLoginBtn = akeeba_passwordless_lookInParentElementsForField(elPasswordlessLoginButton, selector);

    if (elLoginBtn !== null) {
      break;
    }
  }

  if (elLoginBtn === null) {
    return;
  }

  elLoginBtn.parentElement.appendChild(elPasswordlessLoginButton);
}
//# sourceMappingURL=login.js.map