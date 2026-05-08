// Products JavaScript

document.addEventListener('DOMContentLoaded', function() {
    console.log('Products JS loaded');
    
    // Load products on page load
    loadProducts();
});

function loadProducts() {
    fetch('api/get_products.php')
        .then(response => response.json())
        .then(data => {
            displayProducts(data);
        })
        .catch(error => console.error('Error:', error));
}

function displayProducts(products) {
    const container = document.getElementById('products-container');
    
    if (products.length === 0) {
        container.innerHTML = '<p>No products found</p>';
        return;
    }
    
    let html = '';
    products.forEach(product => {
        html += `
            <div class="product-card">
                <img src="${product.image}" alt="${product.name}" class="product-image">
                <h3 class="product-title">${product.name}</h3>
                <p class="product-price">৳ ${product.price}</p>
                <button class="btn btn-primary" onclick="viewProduct(${product.id})">View</button>
                <button class="btn btn-danger" onclick="deleteProduct(${product.id})">Delete</button>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function viewProduct(productId) {
    window.location.href = `view.php?id=${productId}`;
}

function deleteProduct(productId) {
    if (confirm('Are you sure you want to delete this product?')) {
        fetch(`api/delete_product.php?id=${productId}`)
            .then(response => response.json())
            .then(data => {
                alert(data.message);
                loadProducts();
            })
            .catch(error => console.error('Error:', error));
    }
}

function addProduct() {
    window.location.href = 'add.php';
}
