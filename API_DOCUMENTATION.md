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
* **Query Parameters:**
  * `location` (optional, default: `primary`): Theme menu location name.
  * `slug` (optional): Specific menu slug.

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

## TypeScript Interfaces (For Nuxt 3 Frontend)

```typescript
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
