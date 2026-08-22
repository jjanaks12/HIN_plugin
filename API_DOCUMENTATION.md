# Handicraft Auth REST API Documentation

**Base Namespace:** `/wp-json/handicraft/v1`

---

## Endpoints Summary

| Method | Endpoint | Description | Auth Required |
| :--- | :--- | :--- | :--- |
| `POST` | `/auth/login` | Authenticate user & issue JWT token | No |
| `POST` | `/auth/register` | Register new customer or wholesale user & issue JWT token | No |
| `POST` | `/auth/validate` | Verify JWT token validity | No (or Bearer header) |
| `GET` | `/auth/me` | Fetch currently authenticated user profile | **Yes** (`Bearer <token>`) |
| `GET` | `/menus` | Fetch navigation menus tree by location or slug | No |
| `GET` | `/catalog/{slug}` | Fetch category info, subcategories, paginated products, & sorts | No |
| `GET` | `/products` | Fetch paginated catalog products with multi-criteria sorting | No |
| `GET` | `/products/{slug}` | Fetch single product detail, attributes, gallery, reviews, & related products | No |

---

## 1. User Login

Authenticates user credentials and returns a signed JSON Web Token (JWT) along with user profile metadata.

* **URL:** `/wp-json/handicraft/v1/auth/login`
* **Method:** `POST`
* **Headers:** `Content-Type: application/json`

### Request Body
```json
{
  "username": "janak_artisan",
  "password": "SecurePassword123!"
}
```
> **Note:** `username` can be either the WordPress username or user's email address.

### Response `200 OK`
```json
{
  "success": true,
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "tokenType": "Bearer",
  "user": {
    "id": 12,
    "username": "janak_artisan",
    "email": "janak@example.com",
    "firstName": "Janak",
    "lastName": "Shrestha",
    "displayName": "Janak Shrestha",
    "roles": ["wholesale"],
    "isWholesale": true,
    "companyName": "Himalayan Crafts LLC",
    "country": "NP",
    "phone": "+9779800000000",
    "registeredAt": "2026-08-16 13:40:00"
  }
}
```

### Error Responses
* `400 Bad Request` — Missing username or password
* `401 Unauthorized` — Invalid username, email, or password

---

## 2. User Registration

Registers a new user account (Customer B2C or Wholesale B2B). Automatically signs the user in by returning a JWT token.

* **URL:** `/wp-json/handicraft/v1/auth/register`
* **Method:** `POST`
* **Headers:** `Content-Type: application/json`

### Request Body
```json
{
  "email": "buyer@internationalstore.com",
  "password": "SecurePassword123!",
  "username": "intl_buyer",
  "firstName": "John",
  "lastName": "Doe",
  "role": "wholesale",
  "companyName": "Artisan Boutique NY",
  "taxId": "US-987654321",
  "country": "US",
  "phone": "+1234567890"
}
```

| Field | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `email` | `string` | **Yes** | Valid email address (must be unique). |
| `password` | `string` | **Yes** | Password (min 6 characters). |
| `username` | `string` | No | If omitted, derived from email prefix. |
| `firstName` | `string` | No | User's first name. |
| `lastName` | `string` | No | User's last name. |
| `role` | `string` | No | Allowed: `customer` (default) or `wholesale`. |
| `companyName` | `string` | No | B2B Wholesale Company Name. |
| `taxId` | `string` | No | VAT/Tax identification number. |
| `country` | `string` | No | ISO 2-letter country code. |
| `phone` | `string` | No | Contact phone number. |

### Response `201 Created`
```json
{
  "success": true,
  "message": "Account registered successfully.",
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "tokenType": "Bearer",
  "user": {
    "id": 13,
    "username": "intl_buyer",
    "email": "buyer@internationalstore.com",
    "firstName": "John",
    "lastName": "Doe",
    "displayName": "John Doe",
    "roles": ["wholesale"],
    "isWholesale": true,
    "companyName": "Artisan Boutique NY",
    "country": "US",
    "phone": "+1234567890",
    "registeredAt": "2026-08-16 13:45:00"
  }
}
```

### Error Responses
* `400 Bad Request` — Missing email/password or invalid format.
* `409 Conflict` — Username or email already registered.

---

## 3. Validate Token

Validates whether a JWT token is valid, correctly signed, and not expired.

* **URL:** `/wp-json/handicraft/v1/auth/validate`
* **Method:** `POST`
* **Headers:** `Authorization: Bearer <token>` or `Content-Type: application/json`

### Request Body (Optional if Bearer header is sent)
```json
{
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
}
```

### Response `200 OK`
```json
{
  "success": true,
  "valid": true,
  "user": {
    "id": 12,
    "username": "janak_artisan",
    "email": "janak@example.com",
    "roles": ["wholesale"],
    "isWholesale": true
  },
  "exp": 1755420000
}
```

### Error Response `401 Unauthorized`
```json
{
  "success": false,
  "valid": false,
  "code": "jwt_token_expired",
  "message": "Token has expired."
}
```

---

## 4. Get Current User Profile (`/auth/me`)

Retrieves the authenticated user's profile and roles.

* **URL:** `/wp-json/handicraft/v1/auth/me`
* **Method:** `GET`
* **Headers:** `Authorization: Bearer <token>`

### Response `200 OK`
```json
{
  "success": true,
  "user": {
    "id": 12,
    "username": "janak_artisan",
    "email": "janak@example.com",
    "firstName": "Janak",
    "lastName": "Shrestha",
    "displayName": "Janak Shrestha",
    "roles": ["wholesale"],
    "isWholesale": true,
    "companyName": "Himalayan Crafts LLC",
    "country": "NP",
    "phone": "+9779800000000",
    "registeredAt": "2026-08-16 13:40:00"
  }
}
```

### Error Response `401 Unauthorized`
```json
{
  "code": "rest_forbidden",
  "message": "Sorry, you are not allowed to do that.",
  "data": { "status": 401 }
}
```

---

## 5. Get Navigation Menus (`/menus`)

Retrieves hierarchical navigation menu tree for theme locations (e.g. `primary`, `footer`, `mobile`) or by menu slug.

* **URL:** `/wp-json/handicraft/v1/menus?location=primary` (or `/wp-json/handicraft/v1/menus/primary`)
* **Method:** `GET`
* **Auth:** Public

### Response `200 OK`
```json
{
  "success": true,
  "location": "primary",
  "menu_name": "Primary Menu",
  "data": [
    {
      "id": 214,
      "label": "Arrival",
      "href": "/arrival",
      "badge": "new",
      "order": 1,
      "children": []
    },
    {
      "id": 215,
      "label": "Incense",
      "href": "/incense",
      "order": 2,
      "children": [
        {
          "id": 216,
          "label": "Ancient Tibetan",
          "href": "/incense/ancient-tibetan",
          "order": 3,
          "children": []
        }
      ]
    }
  ]
}
```

---

## 6. Category Catalog & Products (`/catalog/{slug}`)

Retrieves category metadata (name, description, subcategories), paginated products, and supports multi-criteria sorting.

* **URL:** `/wp-json/handicraft/v1/catalog/{slug}` or `/wp-json/handicraft/v1/catalog?slug={slug}`
* **Method:** `GET`
* **Auth:** Public (includes wholesale dynamic pricing when authenticated via Bearer token)
* **Path / Query Parameters:**
  * `slug` (string, optional): Category slug (`incense`, `ancient-tibetan`, `books`) or collection (`arrival`, `stock`).
  * `sort` (string, optional, default: `latest`):
    * `latest`: Newest arrivals first.
    * `popularity`: Best-selling / popular items first.
    * `rating`: Highest average rating first.
    * `price_low_high`: Lowest price first.
    * `price_high_low`: Highest price first.
    * `title`: Alphabetical (A-Z).
  * `page` (int, optional, default: `1`): Current page.
  * `per_page` (int, optional, default: `24`, max `100`): Items per page.
  * `min_price` / `max_price` (number, optional): Price range filters.
  * `in_stock` (bool, optional): In-stock filter.
  * `search` (string, optional): Keyword search filter.

### Response `200 OK`
```json
{
  "success": true,
  "category": {
    "id": 295,
    "name": "Ancient Tibetan",
    "slug": "ancient-tibetan",
    "description": "Ancient Tibetan Incense Wholesale...",
    "parent": 241,
    "count": 35,
    "image": null,
    "subcategories": [],
    "isSpecial": false
  },
  "pagination": {
    "total": 35,
    "totalPages": 2,
    "currentPage": 1,
    "perPage": 24,
    "hasNext": true,
    "hasPrev": false
  },
  "sort": "latest",
  "availableSorts": [
    { "value": "latest", "label": "Latest Arrivals" },
    { "value": "popularity", "label": "Popularity / Best Selling" },
    { "value": "rating", "label": "Average Rating" },
    { "value": "price_low_high", "label": "Price: Low to High" },
    { "value": "price_high_low", "label": "Price: High to Low" },
    { "value": "title", "label": "Alphabetical (A-Z)" }
  ],
  "products": [
    {
      "id": 1234,
      "name": "Singing Bowls: Sound Healing Collections #1 by Dharmapa",
      "slug": "singing-bowls-sound-healing-collections-1-by-dharmapa",
      "sku": "9786079943189",
      "price": 10.00,
      "regularPrice": 20.00,
      "salePrice": 10.00,
      "wholesalePrice": null,
      "onSale": true,
      "inStock": true,
      "stockQuantity": null,
      "rating": 4.5,
      "reviewCount": 2,
      "images": [
        {
          "id": 12937,
          "src": "https://hin.test/wp-content/uploads/2026/08/singing-bowls-front-1.jpg",
          "thumbnail": "https://hin.test/wp-content/uploads/2026/08/singing-bowls-front-1-300x300.jpg",
          "alt": "Singing Bowls Sound Healing"
        }
      ],
      "featuredImage": "https://hin.test/wp-content/uploads/2026/08/singing-bowls-front-1.jpg",
      "categories": [
        { "id": 303, "name": "Books", "slug": "books" }
      ],
      "shortDescription": "Discover the transformative power of sound...",
      "description": "...",
      "weight": "1",
      "dimensions": { "length": "", "width": "", "height": "" },
      "createdAt": "2026-08-22T03:13:19+00:00"
    }
  ]
}
```

---

## 8. Product Detail

Retrieves single product information by slug or numeric ID, including gallery images, category hierarchy, custom attributes (materials, dimensions), customer reviews, and related products with dynamic wholesale pricing calculations.

* **URL:** `/wp-json/handicraft/v1/products/{slug}`
* **Method:** `GET`
* **Headers:** `X-Country-Code: <2-letter-code>` (Optional), `Authorization: Bearer <token>` (Optional)

### Path Parameters
| Parameter | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `slug` | `string` | **Yes** | Product slug (e.g. `singing-bowl-set`) or numeric product ID (e.g. `124`). |

### Response `200 OK`
```json
{
  "success": true,
  "product": {
    "id": 142,
    "name": "Handmade 7-Metal Tibetan Singing Bowl Set",
    "slug": "handmade-7-metal-tibetan-singing-bowl-set",
    "sku": "SB-TIB-001",
    "price": 85.00,
    "regularPrice": 110.00,
    "salePrice": 85.00,
    "wholesalePrice": 45.00,
    "onSale": true,
    "inStock": true,
    "stockQuantity": 15,
    "rating": 4.9,
    "reviewCount": 8,
    "images": [
      {
        "id": 501,
        "src": "https://example.com/wp-content/uploads/singing-bowl-1.jpg",
        "thumbnail": "https://example.com/wp-content/uploads/singing-bowl-1-300x300.jpg",
        "alt": "Tibetan Singing Bowl with Wooden Striker and Cushion"
      },
      {
        "id": 502,
        "src": "https://example.com/wp-content/uploads/singing-bowl-2.jpg",
        "thumbnail": "https://example.com/wp-content/uploads/singing-bowl-2-300x300.jpg",
        "alt": "Tibetan Singing Bowl side view"
      }
    ],
    "featuredImage": "https://example.com/wp-content/uploads/singing-bowl-1.jpg",
    "categories": [
      { "id": 18, "name": "Singing Bowls", "slug": "singing-bowls" },
      { "id": 12, "name": "Spiritual Crafts", "slug": "spiritual-crafts" }
    ],
    "shortDescription": "Handcrafted Tibetan meditation singing bowl made from seven resonant sacred metals with wooden mallet and hand-sewn ring cushion.",
    "description": "<p>Authentically crafted in the Kathmandu Valley by traditional Newar metalsmiths...</p>",
    "weight": "1.2",
    "dimensions": { "length": "15", "width": "15", "height": "9" },
    "createdAt": "2026-08-15T09:30:00+00:00",
    "attributes": [
      {
        "id": 1,
        "name": "Material",
        "slug": "material",
        "options": ["7 Traditional Metals (Copper, Tin, Zinc, Iron, Lead, Silver, Gold)"],
        "visible": true,
        "variation": false
      },
      {
        "id": 2,
        "name": "Origin",
        "slug": "origin",
        "options": ["Patan, Kathmandu Valley, Nepal"],
        "visible": true,
        "variation": false
      }
    ],
    "relatedProducts": [
      {
        "id": 145,
        "name": "Tingsha Cymbals with Embossed Dragons",
        "slug": "tingsha-cymbals-embossed-dragons",
        "sku": "TC-DRG-002",
        "price": 32.00,
        "regularPrice": 32.00,
        "salePrice": null,
        "wholesalePrice": 18.00,
        "onSale": false,
        "inStock": true,
        "stockQuantity": 20,
        "rating": 5.0,
        "reviewCount": 4,
        "images": [],
        "featuredImage": "https://example.com/wp-content/uploads/tingsha-1.jpg",
        "categories": [{ "id": 18, "name": "Singing Bowls", "slug": "singing-bowls" }],
        "shortDescription": "Traditional bronze Tibetan Tingsha bells.",
        "description": "<p>Hand-tuned meditation cymbals...</p>",
        "createdAt": "2026-08-14T10:00:00+00:00"
      }
    ],
    "reviews": [
      {
        "id": 92,
        "author": "Sarah Jenkins",
        "content": "Incredible sound resonance and beautiful craftsmanship. Packed securely and arrived quickly in the US.",
        "rating": 5,
        "date": "2026-08-18T14:20:00+00:00",
        "verified": true
      }
    ]
  }
}
```

### Error Responses
* `400 Bad Request` — Missing product identifier slug.
* `404 Not Found` — Product does not exist or is not published.

---

## TypeScript Interfaces (For Nuxt 3 Frontend)

```typescript
export interface ProductImage {
  id: number;
  src: string;
  thumbnail: string;
  alt: string;
}

export interface ProductCategoryRef {
  id: number;
  name: string;
  slug: string;
}

export interface SubCategory {
  id: number;
  name: string;
  slug: string;
  count: number;
  href: string;
}

export interface CategoryDetail {
  id: number;
  name: string;
  slug: string;
  description: string;
  rawDescription?: string;
  parent: number;
  count: number;
  image?: string | null;
  subcategories: SubCategory[];
  isSpecial: boolean;
}

export interface ProductItem {
  id: number;
  name: string;
  slug: string;
  sku: string;
  price: number;
  regularPrice: number;
  salePrice: number | null;
  wholesalePrice: number | null;
  onSale: boolean;
  inStock: boolean;
  stockQuantity: number | null;
  rating: number;
  reviewCount: number;
  images: ProductImage[];
  featuredImage: string;
  categories: ProductCategoryRef[];
  shortDescription: string;
  description: string;
  weight?: string;
  dimensions?: {
    length: string;
    width: string;
    height: string;
  };
  createdAt: string;
}

export interface PaginationMeta {
  total: number;
  totalPages: number;
  currentPage: number;
  perPage: number;
  hasNext: boolean;
  hasPrev: boolean;
}

export interface SortOption {
  value: string;
  label: string;
}

export interface CatalogResponse {
  success: boolean;
  category: CategoryDetail;
  pagination: PaginationMeta;
  sort: string;
  availableSorts: SortOption[];
  products: ProductItem[];
}

export interface ProductAttribute {
  id: number;
  name: string;
  slug: string;
  options: string[];
  visible: boolean;
  variation: boolean;
}

export interface ProductReview {
  id: number;
  author: string;
  content: string;
  rating: number;
  date: string;
  verified: boolean;
}

export interface ProductDetail extends ProductItem {
  attributes: ProductAttribute[];
  relatedProducts: ProductItem[];
  reviews: ProductReview[];
}

export interface ProductDetailResponse {
  success: boolean;
  product: ProductDetail;
}

export interface MenuItem {
  id?: number;
  label: string;
  href: string;
  badge?: string;
  target?: string;
  order?: number;
  children?: MenuItem[];
}

export interface MenusResponse {
  success: boolean;
  location: string;
  menu_name?: string;
  data: MenuItem[];
}

export interface UserProfile {
  id: number;
  username: string;
  email: string;
  firstName: string;
  lastName: string;
  displayName: string;
  roles: string[];
  isWholesale: boolean;
  companyName?: string;
  country?: string;
  phone?: string;
  registeredAt: string;
}

export interface AuthResponse {
  success: boolean;
  message?: string;
  token: string;
  tokenType: 'Bearer';
  user: UserProfile;
}

export interface ValidateTokenResponse {
  success: boolean;
  valid: boolean;
  user: UserProfile;
  exp: number;
}
```
