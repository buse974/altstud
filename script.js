// Mobile menu toggle
const menuToggle = document.querySelector(".menu-toggle");
const navLinks = document.querySelector(".nav-links");

menuToggle.addEventListener("click", () => {
  menuToggle.classList.toggle("active");
  navLinks.classList.toggle("active");
  document.body.style.overflow = navLinks.classList.contains("active")
    ? "hidden"
    : "";
});

// Close menu on link click
navLinks.querySelectorAll("a").forEach((link) => {
  link.addEventListener("click", () => {
    menuToggle.classList.remove("active");
    navLinks.classList.remove("active");
    document.body.style.overflow = "";
  });
});

// Navbar scroll effect
const navbar = document.querySelector(".navbar");

window.addEventListener("scroll", () => {
  if (window.pageYOffset > 50) {
    navbar.classList.add("scrolled");
  } else {
    navbar.classList.remove("scrolled");
  }
});

// Smooth reveal animations
const observerOptions = {
  threshold: 0.1,
  rootMargin: "0px 0px -50px 0px",
};

const observer = new IntersectionObserver((entries) => {
  entries.forEach((entry) => {
    if (entry.isIntersecting) {
      entry.target.classList.add("revealed");
    }
  });
}, observerOptions);

// Add reveal class to elements
document
  .querySelectorAll(
    ".service-card, .expertise-category, .highlight, .exp-item, .contact-link",
  )
  .forEach((el) => {
    el.style.opacity = "0";
    el.style.transform = "translateY(30px)";
    el.style.transition = "opacity 0.6s ease, transform 0.6s ease";
    observer.observe(el);
  });

// Add revealed styles
const style = document.createElement("style");
style.textContent = `
    .revealed {
        opacity: 1 !important;
        transform: translateY(0) !important;
    }
`;
document.head.appendChild(style);

// Stagger animation for grid items
document
  .querySelectorAll(
    ".services-grid, .expertise-grid, .founder-highlights, .experience-timeline, .contact-links",
  )
  .forEach((grid) => {
    const items = grid.children;
    Array.from(items).forEach((item, index) => {
      item.style.transitionDelay = `${index * 0.1}s`;
    });
  });

// Contact form
const contactForm = document.getElementById("contactForm");
if (contactForm) {
  contactForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const btn = contactForm.querySelector(".btn-submit");
    const originalText = btn.innerHTML;
    btn.innerHTML = "Envoi en cours...";
    btn.disabled = true;

    const formData = new FormData(contactForm);

    try {
      const response = await fetch("contact.php", {
        method: "POST",
        body: formData,
      });

      const data = await response.json();

      if (data.success) {
        btn.innerHTML = "Envoyé !";
        btn.style.background = "#22c55e";
        contactForm.reset();

        setTimeout(() => {
          btn.innerHTML = originalText;
          btn.style.background = "";
          btn.disabled = false;
        }, 3000);
      } else {
        throw new Error(data.message);
      }
    } catch (error) {
      btn.innerHTML = "Erreur";
      btn.style.background = "#ef4444";

      setTimeout(() => {
        btn.innerHTML = originalText;
        btn.style.background = "";
        btn.disabled = false;
      }, 3000);

      console.error("Erreur:", error);
    }
  });
}
