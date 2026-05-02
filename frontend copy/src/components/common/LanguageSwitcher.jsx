import React, { useEffect, useRef } from 'react';

/**
 * LanguageSwitcher Component
 * 
 * Provides a dropdown to switch between English and Amharic using Google Translate.
 * Since Google Translate widget is already loaded in index.html, this component
 * triggers the widget's language change by simulating a click on the translate menu.
 * 
 * Alternative approach: Use the native Google Translate dropdown by rendering the
 * 'google_translate_element' div in this component. But since it's already in the
 * header, we just use a button that opens the translate widget's language list.
 */

const LanguageSwitcher = () => {
  const initialized = useRef(false);

  // Helper to trigger Google Translate language change
  const changeLanguage = (langCode) => {
    // Google Translate stores a cookie 'googtrans' to track language
    // We can set the cookie and reload the page to apply translation
    const setCookie = (name, value, days) => {
      const date = new Date();
      date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
      const expires = "; expires=" + date.toUTCString();
      document.cookie = name + "=" + (value || "") + expires + "; path=/";
    };

    if (langCode === 'am') {
      setCookie('googtrans', '/en/am', 30);
    } else {
      setCookie('googtrans', '/en/en', 30);
    }
    // Reload the page to apply translation
    window.location.reload();
  };

  // Alternative: Use the Google Translate API to show the dropdown
  const showTranslateDropdown = () => {
    // Find the Google Translate select element and trigger click
    const translateSelect = document.querySelector('.goog-te-combo');
    if (translateSelect) {
      translateSelect.click();
    } else {
      // Fallback: reload with cookie method maybe not working, so we show a message
      alert('Please use the Google Translate dropdown in the top-right corner.');
    }
  };

  return (
    <div className="dropdown">
      <button
        className="btn btn-outline-light btn-sm dropdown-toggle"
        type="button"
        id="languageDropdown"
        data-bs-toggle="dropdown"
        aria-expanded="false"
      >
        🌐 Language / ቋንቋ
      </button>
      <ul className="dropdown-menu dropdown-menu-end" aria-labelledby="languageDropdown">
        <li>
          <button className="dropdown-item" onClick={() => changeLanguage('en')}>
            🇬🇧 English
          </button>
        </li>
        <li>
          <button className="dropdown-item" onClick={() => changeLanguage('am')}>
            🇪🇹 አማርኛ (Amharic)
          </button>
        </li>
        <li><hr className="dropdown-divider" /></li>
        <li>
          <button className="dropdown-item" onClick={showTranslateDropdown}>
            🔄 Open Translate Menu
          </button>
        </li>
      </ul>
    </div>
  );
};

export default LanguageSwitcher;