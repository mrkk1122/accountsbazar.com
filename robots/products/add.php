<?php
/**
 * Add New Product
 */

require_once 'config/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Product</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(14, 165, 233, 0.18), transparent 30%),
                radial-gradient(circle at top right, rgba(245, 158, 11, 0.16), transparent 28%),
                linear-gradient(180deg, #eef6ff 0%, #f8fafc 45%, #eef2ff 100%);
        }

        .add-product-shell {
            max-width: 980px;
            margin: 38px auto 70px;
            padding: 0 16px;
        }

        .add-product-card {
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 24px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.12);
            overflow: hidden;
        }

        .add-product-hero {
            padding: 28px 28px 20px;
            background: linear-gradient(135deg, #0f172a, #1d4ed8 58%, #38bdf8 100%);
            color: #fff;
        }

        .add-product-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            margin-bottom: 12px;
        }

        .add-product-title {
            font-size: 34px;
            line-height: 1.1;
            margin-bottom: 10px;
            font-weight: 900;
        }

        .add-product-subtitle {
            max-width: 700px;
            color: rgba(255, 255, 255, 0.86);
            font-size: 15px;
        }

        .add-product-body {
            padding: 28px;
        }

        .add-product-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .add-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .add-field.full {
            grid-column: 1 / -1;
        }

        .add-field label {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
        }

        .add-field input,
        .add-field textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            padding: 14px 15px;
            font-size: 15px;
            color: #0f172a;
            background: #fff;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        }

        .add-field textarea {
            min-height: 150px;
            resize: vertical;
        }

        .add-field input:focus,
        .add-field textarea:focus {
            outline: none;
            border-color: #38bdf8;
            box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.16);
            transform: translateY(-1px);
        }

        .upload-panel {
            border: 1px dashed #93c5fd;
            background: linear-gradient(180deg, #f8fbff 0%, #eff6ff 100%);
            border-radius: 16px;
            padding: 18px;
        }

        .upload-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .upload-note {
            color: #475569;
            font-size: 13px;
        }

        .upload-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 98px;
            padding: 6px 12px;
            border-radius: 999px;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 900;
        }

        .add-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            flex-wrap: wrap;
        }

        .add-submit-btn,
        .add-cancel-btn {
            min-width: 160px;
            border-radius: 14px;
            padding: 13px 20px;
            text-align: center;
            text-decoration: none;
            font-size: 15px;
            font-weight: 900;
            border: none;
            cursor: pointer;
        }

        .add-submit-btn {
            background: linear-gradient(135deg, #0f172a, #2563eb);
            color: #fff;
            box-shadow: 0 14px 28px rgba(37, 99, 235, 0.22);
        }

        .add-submit-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .add-cancel-btn {
            background: #e2e8f0;
            color: #0f172a;
        }

        .add-status {
            display: none;
            margin-top: 18px;
            padding: 14px 16px;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 700;
        }

        .add-status.success {
            display: block;
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .add-status.error {
            display: block;
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        @media (max-width: 767.98px) {
            .add-product-shell {
                margin-top: 18px;
            }

            .add-product-hero,
            .add-product-body {
                padding: 18px;
            }

            .add-product-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .add-product-title {
                font-size: 28px;
            }

            .add-submit-btn,
            .add-cancel-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="add-product-shell">
        <div class="add-product-card">
            <div class="add-product-hero">
                <div class="add-product-kicker">Admin Product Studio</div>
                <h1 class="add-product-title">Add New Product</h1>
                <p class="add-product-subtitle">Create a polished product entry with strong titles, clean description, exact pricing, and four showcase images for the storefront slider.</p>
            </div>

            <div class="add-product-body">
                <form id="add-product-form" method="POST" action="api/create_product.php" enctype="multipart/form-data">
                    <div class="add-product-grid">
                        <div class="add-field">
                            <label for="name">Product Name</label>
                            <input type="text" id="name" name="name" placeholder="Enter product title" required>
                        </div>

                        <div class="add-field">
                            <label for="price">Price</label>
                            <input type="number" id="price" name="price" step="0.01" placeholder="0.00" required>
                        </div>

                        <div class="add-field">
                            <label for="quantity">Quantity</label>
                            <input type="number" id="quantity" name="quantity" placeholder="Available stock" required>
                        </div>

                        <div class="add-field full">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" rows="5" placeholder="Write benefits, features, access details, and usage info..."></textarea>
                        </div>

                        <div class="add-field full">
                            <label for="images">Product Images</label>
                            <div class="upload-panel">
                                <input type="file" id="images" name="images[]" accept="image/*" multiple required>
                                <div class="upload-meta">
                                    <span class="upload-note">Upload exactly 4 images. These will be used in product details slider and storefront preview.</span>
                                    <span class="upload-count" id="upload-count">0 / 4 selected</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="add-actions">
                        <button type="submit" class="add-submit-btn">Add Product</button>
                        <a href="index.php" class="add-cancel-btn">Cancel</a>
                    </div>

                    <div id="add-product-status" class="add-status"></div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('add-product-form');
            const imageInput = document.getElementById('images');
            const countLabel = document.getElementById('upload-count');
            const statusBox = document.getElementById('add-product-status');

            if (!form) {
                return;
            }

            function setStatus(type, text) {
                statusBox.className = 'add-status ' + type;
                statusBox.textContent = text;
            }

            function refreshUploadCount() {
                const totalFiles = imageInput && imageInput.files ? imageInput.files.length : 0;
                if (countLabel) {
                    countLabel.textContent = totalFiles + ' / 4 selected';
                }
            }

            if (imageInput) {
                imageInput.addEventListener('change', refreshUploadCount);
            }

            form.addEventListener('submit', async function (event) {
                event.preventDefault();

                const totalFiles = imageInput && imageInput.files ? imageInput.files.length : 0;
                if (totalFiles !== 4) {
                    setStatus('error', 'Please upload exactly 4 product images.');
                    return;
                }

                const formData = new FormData(form);
                const submitButton = form.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.textContent = 'Saving Product...';
                }

                statusBox.className = 'add-status';
                statusBox.textContent = '';

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        setStatus('success', 'Success! Product added successfully.');
                        form.reset();
                        refreshUploadCount();
                    } else {
                        setStatus('error', 'Failed: ' + (data.message || 'Unable to add product.'));
                    }
                } catch (error) {
                    setStatus('error', 'Error: Could not submit product right now.');
                } finally {
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.textContent = 'Add Product';
                    }
                }
            });

            refreshUploadCount();
        });
    </script>
</body>
</html>
