// Mobile Menu
const mobileMenuBtn = document.getElementById('mobileMenuBtn');
const mobileMenuClose = document.getElementById('mobileMenuClose');
const mobileMenu = document.getElementById('mobileMenu');

mobileMenuBtn?.addEventListener('click', () => mobileMenu.classList.add('active'));
mobileMenuClose?.addEventListener('click', () => mobileMenu.classList.remove('active'));

// Header scroll effect
const header = document.querySelector('.header');
window.addEventListener('scroll', () => {
    if (window.scrollY > 50) {
        header.classList.add('scrolled');
    } else {
        header.classList.remove('scrolled');
    }
});

// Smooth reveal animations
const observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -50px 0px'
};

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('revealed');
        }
    });
}, observerOptions);

// Apply reveal animation to elements
document.querySelectorAll('.service-card, .listing-card, .process-step, .testimonial-card, .stat-item').forEach(el => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(30px)';
    el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
    observer.observe(el);
});

// Add revealed styles
const style = document.createElement('style');
style.textContent = `.revealed { opacity: 1 !important; transform: translateY(0) !important; }`;
document.head.appendChild(style);

// Stagger animation for grids
document.querySelectorAll('.services-grid, .listings-grid, .about-stats, .process-grid').forEach(grid => {
    const children = grid.children;
    Array.from(children).forEach((child, index) => {
        child.style.transitionDelay = `${index * 0.1}s`;
    });
});

// Parallax effect for hero
const heroVisual = document.querySelector('.hero-visual');
if (heroVisual) {
    window.addEventListener('scroll', () => {
        const scrolled = window.pageYOffset;
        if (scrolled < window.innerHeight) {
            heroVisual.style.transform = `translateY(${scrolled * 0.1}px)`;
        }
    });
}

// Counter animation for stats
function animateCounter(el, target, suffix = '') {
    let current = 0;
    const increment = target / 40;
    const timer = setInterval(() => {
        current += increment;
        if (current >= target) {
            current = target;
            clearInterval(timer);
        }
        el.textContent = Math.floor(current).toLocaleString() + suffix;
    }, 40);
}

const statsSection = document.querySelector('.about-stats');
if (statsSection) {
    const statsObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.querySelectorAll('.stat-number').forEach(stat => {
                    if (stat.dataset.animated) return;
                    stat.dataset.animated = 'true';
                    
                    const text = stat.textContent;
                    const number = parseInt(text.replace(/[^0-9]/g, ''));
                    let suffix = '';
                    if (text.includes('+')) suffix = '+';
                    else if (text.includes('%')) suffix = '%';
                    else if (text.includes('h')) suffix = 'h';
                    
                    if (number) animateCounter(stat, number, suffix);
                });
            }
        });
    }, { threshold: 0.3 });
    
    statsObserver.observe(statsSection);
}

// Magnetic effect for buttons
document.querySelectorAll('.btn-primary, .btn-outline').forEach(btn => {
    btn.addEventListener('mousemove', (e) => {
        const rect = btn.getBoundingClientRect();
        const x = e.clientX - rect.left - rect.width / 2;
        const y = e.clientY - rect.top - rect.height / 2;
        btn.style.transform = `translate(${x * 0.1}px, ${y * 0.1}px)`;
    });
    
    btn.addEventListener('mouseleave', () => {
        btn.style.transform = 'translate(0, 0)';
    });
});

// Image hover zoom
document.querySelectorAll('.listing-card, .service-card').forEach(card => {
    const img = card.querySelector('img');
    if (img) {
        card.addEventListener('mouseenter', () => {
            img.style.transform = 'scale(1.05)';
        });
        card.addEventListener('mouseleave', () => {
            img.style.transform = 'scale(1)';
        });
    }
});

// Cursor follower for visual interest (optional - desktop only)
if (window.innerWidth > 1024) {
    const cursor = document.createElement('div');
    cursor.className = 'custom-cursor';
    cursor.innerHTML = '<div class="cursor-dot"></div><div class="cursor-ring"></div>';
    document.body.appendChild(cursor);
    
    const cursorStyle = document.createElement('style');
    cursorStyle.textContent = `
        .custom-cursor { position: fixed; pointer-events: none; z-index: 10000; }
        .cursor-dot { width: 6px; height: 6px; background: var(--color-accent); border-radius: 50%; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); transition: transform 0.1s ease; }
        .cursor-ring { width: 32px; height: 32px; border: 1px solid var(--color-accent); border-radius: 50%; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); transition: all 0.15s ease; opacity: 0.5; }
        .custom-cursor.hover .cursor-ring { width: 50px; height: 50px; opacity: 1; }
        .custom-cursor.hover .cursor-dot { transform: translate(-50%, -50%) scale(2); }
        body { cursor: none; }
        a, button { cursor: none; }
    `;
    document.head.appendChild(cursorStyle);
    
    document.addEventListener('mousemove', (e) => {
        cursor.style.left = e.clientX + 'px';
        cursor.style.top = e.clientY + 'px';
    });
    
    document.querySelectorAll('a, button, .listing-card, .service-card').forEach(el => {
        el.addEventListener('mouseenter', () => cursor.classList.add('hover'));
        el.addEventListener('mouseleave', () => cursor.classList.remove('hover'));
    });
}

console.log('Colocation Adulte - Textured Design loaded');
