import React from 'react';

const Footer = () => {
  const currentYear = new Date().getFullYear();

  return (
    <footer className="bg-light text-center text-lg-start mt-5 border-top">
      <div className="container p-4">
        <div className="row">
          <div className="col-lg-6 col-md-12 mb-4 mb-md-0">
            <h5 className="text-uppercase">Vaccine Management System</h5>
            <p>
              A digital solution for childhood immunizations in Ethiopia, following the 
              Federal Ministry of Health EPI schedule. Ensuring timely vaccinations for children 0-5 years.
            </p>
          </div>
          <div className="col-lg-3 col-md-6 mb-4 mb-md-0">
            <h5 className="text-uppercase">Quick Links</h5>
            <ul className="list-unstyled mb-0">
              <li><a href="/about" className="text-dark">About Us</a></li>
              <li><a href="/contact" className="text-dark">Contact</a></li>
              <li><a href="/privacy" className="text-dark">Privacy Policy</a></li>
              <li><a href="/terms" className="text-dark">Terms of Use</a></li>
            </ul>
          </div>
          <div className="col-lg-3 col-md-6 mb-4 mb-md-0">
            <h5 className="text-uppercase">Contact</h5>
            <ul className="list-unstyled mb-0">
              <li>📞 +251-11-123-4567</li>
              <li>✉️ support@vaccine-ms.com</li>
              <li>📍 Addis Ababa, Ethiopia</li>
            </ul>
          </div>
        </div>
      </div>
      <div className="text-center p-3 bg-dark text-white">
        © {currentYear} Ethiopian Vaccine Management System. All rights reserved.
        <br />
        <small>Powered by the Federal Ministry of Health, Ethiopia</small>
      </div>
    </footer>
  );
};

export default Footer;