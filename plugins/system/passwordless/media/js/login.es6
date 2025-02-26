/*!
 * @package   AkeebaPasswordlessLogin
 * @copyright Copyright (c)2018-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * Akeeba Passwordless Login client-side implementation
 *
 * This is the EcmaScript 6+ source of the client-side implementation. It is meant to be transpiled to ES5.1 (plain old
 * JavaScript) with Babel. The actual file being loaded can be found in dist/passwordless.js.
 */
((Joomla, document) => {
	"use strict";

	var Passwordless = {};

	/**
	 * Converts a simple object containing query string parameters to a single, escaped query string.
	 * This method is a necessary evil since Joomla.request can only accept data as a string.
	 *
	 * @param    object   {object}  A plain object containing the query parameters to pass
	 * @param    prefix   {string}  Prefix for array-type parameters
	 *
	 * @returns  {string}
	 */
	Passwordless.interpolateParameters = (object, prefix = '') => {
		let encodedString = '';

		Object.keys(object).forEach((prop) => {
			if (typeof object[prop] !== 'object')
			{
				if (encodedString.length > 0)
				{
					encodedString += '&';
				}

				if (prefix === '')
				{
					encodedString += `${encodeURIComponent(prop)}=${encodeURIComponent(object[prop])}`;
				}
				else
				{
					encodedString
						+= `${encodeURIComponent(prefix)}[${encodeURIComponent(prop)}]=${encodeURIComponent(
						object[prop],
					)}`;
				}

				return;
			}

			// Objects need special handling
			encodedString += `${Passwordless.interpolateParameters(object[prop], prop)}`;
		});

		return encodedString;
	};

	/**
	 * Finds the first field matching a selector inside a form
	 *
	 * @param   {HTMLFormElement}  form           The FORM element
	 * @param   {String}           fieldSelector  The CSS selector to locate the field
	 *
	 * @returns {Element|null}  NULL when no element is found
	 */
	Passwordless.findField = (form, fieldSelector) => {
		const elInputs = form.querySelectorAll(fieldSelector);

		if (!elInputs.length)
		{
			return null;
		}

		return elInputs[0];
	};

	/**
	 * Find a form field described by the CSS selector fieldSelector.
	 * The field must be inside a <form> element which is either the
	 * outerElement itself or enclosed by outerElement.
	 *
	 * @param   {Element}  outerElement   The element which is either our form or contains our form.
	 * @param   {String}   fieldSelector  The CSS selector to locate the field
	 *
	 * @returns {null|Element}  NULL when no element is found
	 */
	Passwordless.lookForField = (outerElement, fieldSelector) => {
		let elInput = null;

		if (!outerElement)
		{
			return elInput;
		}

		const elElement = outerElement.parentElement;

		if (elElement.nodeName === 'FORM')
		{
			elInput = Passwordless.findField(elElement, fieldSelector);

			return elInput;
		}

		const elForms = elElement.querySelectorAll('form');

		if (elForms.length)
		{
			for (let i = 0; i < elForms.length; i += 1)
			{
				elInput = Passwordless.findField(elForms[i], fieldSelector);

				if (elInput !== null)
				{
					return elInput;
				}
			}
		}

		return null;
	};

	/**
	 * A simple error handler.
	 *
	 * @param   {String}  message
	 */
	Passwordless.handleLoginError = (message) => {
		Joomla.renderMessages({error: [message]});

		window.scrollTo({top: 0, left: 0, behavior: 'smooth'});
	};

	/**
	 * Handles the browser response for the user interaction with the authenticator. Redirects to an
	 * internal page which handles the login server-side.
	 *
	 * @param   {Object}   publicKey    Public key request options, returned from the server
	 * @param   {boolean}  conditional  Set to true to enable the conditional UI
	 */
	Passwordless.handleLoginChallenge = (publicKey, conditional = false) => {
		const arrayToBase64String = (a) => btoa(String.fromCharCode(...a));

		const base64url2base64 = (input) => {
			let output = input
				.replace(/-/g, '+')
				.replace(/_/g, '/');
			const pad  = output.length % 4;
			if (pad)
			{
				if (pad === 1)
				{
					throw new Error(
						'InvalidLengthError: Input base64url string is the wrong length to determine padding');
				}
				output += new Array(5 - pad).join('=');
			}
			return output;
		};

		if (!publicKey.challenge)
		{
			Passwordless.handleLoginError(Joomla.Text._("PLG_SYSTEM_PASSWORDLESS_ERR_INVALID_USERNAME"));

			return;
		}

		publicKey.challenge = Uint8Array.from(
			window.atob(base64url2base64(publicKey.challenge)), (c) => c.charCodeAt(0),
		);

		if (publicKey.allowCredentials)
		{
			publicKey.allowCredentials = publicKey.allowCredentials.map((data) => {
				data.id = Uint8Array.from(window.atob(base64url2base64(data.id)), (c) => c.charCodeAt(0));
				return data;
			});
		}

		let credOptions = {
			publicKey: publicKey
		};

		if (conditional)
		{
			const abortController = new AbortController();
			credOptions.signal = abortController.signal;
			credOptions.mediation = 'conditional';
		}

		console.log(credOptions);

		navigator.credentials.get(credOptions)
				   .then((data) => {
				const publicKeyCredential = {
					id:       data.id,
					type:     data.type,
					rawId:    arrayToBase64String(new Uint8Array(data.rawId)),
					response: {
						authenticatorData: arrayToBase64String(new Uint8Array(data.response.authenticatorData)),
						clientDataJSON:    arrayToBase64String(new Uint8Array(data.response.clientDataJSON)),
						signature:         arrayToBase64String(new Uint8Array(data.response.signature)),
						userHandle:        data.response.userHandle ? arrayToBase64String(
							new Uint8Array(data.response.userHandle),
						) : null,
					},
				};

				// Send the response to your server
				const paths     = Joomla.getOptions('system.paths');
				window.location =
					`${paths ? `${paths.base}/index.php` : window.location.pathname}?${Joomla.getOptions(
						'csrf.token')}=1&option=com_ajax&group=system&plugin=passwordless`
					+ `&format=raw&akaction=login&encoding=redirect&data=${
						btoa(JSON.stringify(publicKeyCredential))}`;
			})
				   .catch((error) => {
				// Example: timeout, interaction refused...
				Passwordless.handleLoginError(error);
			});
	};

	/**
	 * Initialize the passwordless login, going through the server to get the registered certificates
	 * for the user.
	 *
	 * @param   {string}   formId       The login form's or login module's HTML ID
	 * @param   {boolean}  conditional  Set to true to enable the conditional UI
	 *
	 * @returns {boolean}  Always FALSE to prevent BUTTON elements from reloading the page.
	 */
	// eslint-disable-next-line no-unused-vars
	Passwordless.login = (formId, conditional = false) => {
		const elFormContainer = document.getElementById(formId);
		const elUsername      = Passwordless.lookForField(elFormContainer, 'input[name=username]');
		const elReturn        = Passwordless.lookForField(elFormContainer, 'input[name=return]');

		const username  = elUsername?.value ?? '';
		const returnUrl = elReturn ? elReturn.value : null;

		// Get the Public Key Credential Request Options (challenge and acceptable public keys)
		const postBackData                            = {
			option:   "com_ajax",
			group:    "system",
			plugin:   "passwordless",
			format:   "raw",
			akaction: "challenge",
			encoding: "raw",
			username,
			returnUrl,
		};
		postBackData[Joomla.getOptions('csrf.token')] = 1;

		const paths = Joomla.getOptions('system.paths');
		const url   = paths ? (paths.base + '/index.php') : window.location.pathname;

		Joomla.request({
			url:    url,
			method: "POST",
			data:   Passwordless.interpolateParameters(postBackData),
			onSuccess(rawResponse)
			{
				let jsonData = {};

				try
				{
					jsonData = JSON.parse(rawResponse);
				}
				catch (e)
				{
					/**
					 * In case of JSON decoding failure fall through; the error will be handled in the login
					 * challenge handler called below.
					 */
				}

				if (jsonData.error)
				{
					Passwordless.handleLoginError(jsonData.error);

					return;
				}

				console.log(jsonData);

				Passwordless.handleLoginChallenge(jsonData, conditional);
			},
			onError: (xhr) => {
				Passwordless.handleLoginError(`${xhr.status} ${xhr.statusText}`);
			},
		});

		return false;
	};

	Passwordless.initLogin = async () => {
		// Find all login buttons on the page
		const loginButtons  = [].slice.call(document.querySelectorAll(".plg_system_passwordless_login_button"));

		// If there are no login buttons we can return immediately
		if (!loginButtons.length)
		{
			return;
		}

		// Check if WebAuthn and conditional mediation for it are available
		const hasWebAuthn   = typeof window.PublicKeyCredential !== "undefined";
		let formNames = [];

		const pluginOptions   = Joomla.getOptions("plg_system_passwordless");
		const allowAutoDetect = pluginOptions?.autodetect ?? false;
		let canAutodetect     = false;

		if (allowAutoDetect && hasWebAuthn && typeof PublicKeyCredential.isConditionalMediationAvailable !== "undefined")
		{
			canAutodetect = await PublicKeyCredential.isConditionalMediationAvailable();
		}

		console.log('Passkey autodetection: ' + (canAutodetect ? 'enabled' : 'disabled'));

		// Process all login buttons
		loginButtons.forEach((button) =>
		{
			// If WebAuthn is not available let's hide the button and get out of here.
			if (!hasWebAuthn)
			{
				button.style.display = "none";

				return;
			}

			// Stash the ID of the form the login button belongs to. We'll need it later.
			formNames.push(button.getAttribute("data-passwordless-form"))

			// Add the click handler.
			button.addEventListener("click", (e) =>
			{
				e.preventDefault();

				const currentTarget = e.currentTarget;

				Passwordless.login(
					currentTarget.getAttribute("data-passwordless-form")
				);
			});
		});

		// The following code only applies when conditional mediation is available. If it's not available, go away.
		if (!formNames.length || !canAutodetect)
		{
			return;
		}

		let lastFormId = null;

		// This sets the autocomplete attribute of the username field of every unique login form.
		[...new Set(formNames)].forEach((formId) => {
            const elFormContainer = document.getElementById(formId);

            if (!elFormContainer)
            {
                return;
            }

            const elUsername = Passwordless.lookForField(elFormContainer, "input[name=username]");

            if (!elUsername)
            {
                return;
            }

            elUsername.setAttribute("autocomplete", "username webauthn");

			lastFormId = formId
		});

		if (!lastFormId)
		{
			return;
		}

		Passwordless.login(lastFormId, true);
	}

	// Initialization. Runs on DOM content loaded since this script is always loaded deferred.
	Passwordless.initLogin();

})(Joomla, document);
