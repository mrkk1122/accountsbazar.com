const menuToggle = document.querySelector('.menu-toggle');
const mainNav = document.getElementById('mainNav');
const filterButtons = document.querySelectorAll('.filter-btn');
const revealItems = document.querySelectorAll('.reveal');
const searchForm = document.getElementById('searchForm');
const searchInput = document.getElementById('searchInput');
const listingGrid = document.getElementById('listingGrid');
const productsStatus = document.getElementById('productsStatus');

let selectedCategory = 'all';
let selectedQuery = '';

const fallbackProducts = [
  {
    category: 'social',
    platform: 'Instagram',
    title: 'Fashion Niche Handle',
    description: '185K followers, high story reach, organic audience.',
    badge: 'Trusted Seller',
    price_usd: 1450
  },
  {
    category: 'gaming',
    platform: 'PUBG Mobile',
    title: 'Conqueror ID + Rare Set',
    description: 'Season elite history, mythic inventory, transfer-ready.',
    badge: 'Escrow Ready',
    price_usd: 860
  },
  {
    category: 'business',
    platform: 'Meta Ads',
    title: 'Verified Ad Account',
    description: 'Long spend history, low risk profile, instant handover.',
    badge: 'Fast Transfer',
    price_usd: 2300
  }
];

const testimonials = [
  {
    quote: '"I sold three gaming IDs in one week with zero fake buyers. The process was smooth from listing to payout."',
    author: 'Rafiq Hasan, Seller'
  },
  {
    quote: '"Bought a verified business ad account in less than an hour. Support team was responsive and clear."',
    author: 'Nusrat Jahan, Buyer'
  },
  {
    quote: '"The escrow flow gave me confidence to trade higher-value social pages safely."',
    author: 'Shuvo Rahman, Agency Owner'
  }
];

let quoteIndex = 0;
const quoteText = document.getElementById('quoteText');
const quoteAuthor = document.getElementById('quoteAuthor');

if (menuToggle && mainNav) {
  menuToggle.addEventListener('click', () => {
    const isOpen = mainNav.classList.toggle('open');
    menuToggle.setAttribute('aria-expanded', String(isOpen));
  });
}

filterButtons.forEach((button) => {
  button.addEventListener('click', () => {
    const selected = button.getAttribute('data-filter') || 'all';
    selectedCategory = selected;

    filterButtons.forEach((b) => b.classList.remove('active'));
    button.classList.add('active');

    loadProducts();
  });
});

if (searchForm && searchInput) {
  searchForm.addEventListener('submit', (event) => {
    event.preventDefault();
    selectedQuery = searchInput.value.trim();
    loadProducts();
  });
}

const observer = new IntersectionObserver(
  (entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        observer.unobserve(entry.target);
      }
    });
  },
  { threshold: 0.12 }
);

revealItems.forEach((item) => observer.observe(item));

function formatPrice(price) {
  const safePrice = Number(price || 0);
  return `$${safePrice.toLocaleString('en-US', { maximumFractionDigits: 2 })}`;
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function buildListingCard(product) {
  const category = escapeHtml(product.category);
  const platform = escapeHtml(product.platform);
  const title = escapeHtml(product.title);
  const description = escapeHtml(product.description);
  const badge = escapeHtml(product.badge || product.seller_status);

  return `
    <article class="listing reveal visible" data-type="${category}">
      <p class="tag">${platform}</p>
      <h3>${title}</h3>
      <p>${description}</p>
      <div class="listing-meta"><span>${badge}</span><strong>${formatPrice(product.price_usd)}</strong></div>
    </article>
  `;
}

function setStatus(message) {
  if (!productsStatus) {
    return;
  }

  productsStatus.textContent = message;
}

async function loadProducts() {
  if (!listingGrid) {
    return;
  }

  setStatus('Loading products from database...');

  const params = new URLSearchParams();

  if (selectedCategory !== 'all') {
    params.set('category', selectedCategory);
  }

  if (selectedQuery !== '') {
    params.set('q', selectedQuery);
  }

  const url = `api/products.php?${params.toString()}`;

  try {
    const response = await fetch(url, { cache: 'no-store' });

    if (!response.ok) {
      throw new Error(`Request failed (${response.status})`);
    }

    const payload = await response.json();

    if (!payload.success || !Array.isArray(payload.products)) {
      throw new Error('Invalid API payload');
    }

    if (payload.products.length === 0) {
      listingGrid.innerHTML = '';
      setStatus('No products found for your filter/search.');
      return;
    }

    listingGrid.innerHTML = payload.products.map(buildListingCard).join('');
    setStatus(`Showing ${payload.products.length} product(s)`);
  } catch (error) {
    const filtered = fallbackProducts.filter((product) => {
      const categoryMatch = selectedCategory === 'all' || product.category === selectedCategory;
      const q = selectedQuery.toLowerCase();
      const queryMatch = q === '' || `${product.title} ${product.platform} ${product.description}`.toLowerCase().includes(q);
      return categoryMatch && queryMatch;
    });

    listingGrid.innerHTML = filtered.map(buildListingCard).join('');
    setStatus('Live database unavailable, showing demo products.');
    console.error(error);
  }
}

loadProducts();

if (quoteText && quoteAuthor) {
  setInterval(() => {
    quoteIndex = (quoteIndex + 1) % testimonials.length;
    quoteText.textContent = testimonials[quoteIndex].quote;
    quoteAuthor.textContent = testimonials[quoteIndex].author;
  }, 4500);
}
