# Premium Auth Design System

## Overview

The authentication system uses a modern, premium split-screen design inspired by infrastructure SaaS platforms like Vercel, Linear, and DevZero. The design maintains consistency across all auth flows while providing purpose-built layouts for each page.

---

## Design Architecture

### Layout: `layouts/auth-premium.blade.php`

The premium auth layout provides a split-screen experience:

**Left Panel (100% on mobile, 50% on desktop)**
- White/dark background
- Logo and brand at top-left
- Centered form area with excellent whitespace
- Footer with terms/privacy links
- Responsive padding adjusts for all screen sizes

**Right Panel (Hidden on mobile, 50% on desktop)**
- Dark gradient background (slate-900 → purple-900)
- Decorative futuristic infrastructure visual treatment
- Floating cards with animated infrastructure metrics
- Glow effects and grid backgrounds
- SVG connecting lines with gradient strokes
- Creates premium "deployment platform" aesthetic

### Visual Language

**Colors**
- Primary accent: Purple (`#a78bfa` → `#7c3aed`)
- Dark backgrounds: Slate-900 with purple tones
- Text: Slate-900 on light, white on dark
- Borders: Subtle slate-200/700
- Focus states: Purple with ring effect

**Typography**
- Font: Inter (from fonts.bunny.net)
- Headings: 3xl font-bold (h1 elements)
- Subtext: Small/xs size, muted color
- Labels: sm font-medium
- Inputs: sm font-normal

**Spacing**
- Page margins: px-6 lg:px-12 (responsive padding)
- Section spacing: space-y-5 to space-y-6
- Form field spacing: space-y-3.5 to space-y-4
- Label-input spacing: mb-2

**Borders & Shadows**
- Borders: `border border-slate-200 dark:border-slate-700`
- Shadows: Subtle (shadow-md on right panel)
- Border radius: `rounded-lg` (inputs), `rounded-xl` (cards)

**Interactive States**
- Focus: `focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500`
- Hover: `hover:text-purple-700` (links), `hover:bg-slate-800` (buttons)
- Active: `active:scale-95` (buttons)
- Transitions: All use `transition` class

---

## Auth Pages & Components

### 1. Login Page
**File**: `auth/login-premium.blade.php`
**Route**: `/login`
**Controller**: `AuthenticatedSessionController@create()`

**Components**:
- Email input with placeholder
- Password input with visibility toggle
- "Forgot password?" link in label
- Remember me checkbox
- Primary "Sign in" button
- Divider with "or continue with" text
- Social auth buttons (Google, GitHub)
- "Don't have an account? Sign up" link

**Data**: None required from controller

---

### 2. Register Page
**File**: `auth/register-premium.blade.php`
**Route**: `/register`
**Controller**: `RegisteredUserController@create()`

**Components**:
- Full name input (required)
- Company input (optional)
- Email input (required)
- Password input with visibility toggle
- Confirm password input (hidden)
- Terms & Privacy checkbox (required)
- Primary "Create account" button
- Divider with "or continue with" text
- Social signup buttons (Google, GitHub)
- "Already have an account? Sign in" link

**Data**: None required from controller

**Validation**: Updated to include `company` and `agree` fields in `RegisteredUserController@store()`

---

### 3. Forgot Password Page
**File**: `auth/forgot-password-premium.blade.php`
**Route**: `/forgot-password`
**Controller**: `PasswordResetLinkController@create()`

**Components**:
- Email input (required)
- Primary "Send reset link" button
- Back to sign in link

**Context**: For users who forgot their password

---

### 4. Reset Password Page
**File**: `auth/reset-password-premium.blade.php`
**Route**: `/reset-password/{token}`
**Controller**: `NewPasswordController@create(Request $request)`

**Components**:
- Email input (pre-filled from token)
- Password input with visibility toggle
- Confirm password input with visibility toggle
- Primary "Reset password" button
- Back to sign in link

**Data**: `$request` object containing email and token

**Special**: Dual password visibility toggles for password confirmation

---

### 5. Verify Email Page
**File**: `auth/verify-email-premium.blade.php`
**Route**: `/verify-email` (after registration)
**Controller**: `EmailVerificationPromptController@__invoke(Request $request)`

**Components**:
- Purple icon in circular badge
- "Verify your email" heading
- Status message if link was sent
- Info box with "Didn't receive email?" message
- "Resend verification email" button
- "Sign out and try again" button
- Help text with support link

**Context**: After user registers, before dashboard access

**Redirect**: Auto-redirects to dashboard if already verified

---

### 6. Confirm Password Page
**File**: `auth/confirm-password-premium.blade.php`
**Route**: `/confirm-password`
**Controller**: `ConfirmablePasswordController@show()`

**Components**:
- Amber icon in circular badge
- "Confirm your password" heading
- Password input with visibility toggle
- Primary "Confirm password" button
- Help text with password reset link

**Context**: Sensitive operations (profile, settings changes)

---

## Reusable Components

### `components/auth-input.blade.php`

Basic text input component for auth forms.

**Usage**:
```blade
<x-auth-input
    name="email"
    type="email"
    label="Email address"
    placeholder="you@company.com"
    required
    autofocus
    autocomplete="email"
    :error="$errors->first('email')"
/>
```

**Props**:
- `name` (required): Input name/id
- `type` (default: 'text'): Input type
- `label`: Optional label text
- `placeholder`: Placeholder text
- `required`: Boolean for required attribute
- `autofocus`: Boolean for autofocus
- `autocomplete`: Autocomplete attribute value
- `value`: Pre-filled value
- `error`: Error message to display
- Additional HTML attributes via `{{ $attributes }}`

---

### `components/auth-password-input.blade.php`

Password input with visibility toggle.

**Usage**:
```blade
<x-auth-password-input
    name="password"
    label="Password"
    placeholder="••••••••"
    required
    autocomplete="new-password"
    :error="$errors->first('password')"
/>
```

**Props**:
- `name` (required): Input name/id
- `label`: Optional label text
- `placeholder`: Placeholder text (default: empty)
- `required`: Boolean for required attribute
- `autocomplete`: Autocomplete attribute value
- `value`: Pre-filled value
- `error`: Error message to display
- `toggleId`: Custom toggle button ID (auto-generated if not provided)
- Additional HTML attributes via `{{ $attributes }}`

**Features**:
- Alpine.js powered toggle (`:show` data property)
- Eye icon shows/hides password
- Works with dark mode

---

## CSS Classes

### `.auth-input`
Applied to all form inputs in auth pages.

```css
@apply w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg px-4 py-2.5 text-sm transition-all;
@apply focus:outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 dark:focus:ring-purple-400/20 dark:focus:border-purple-400;
```

**Features**:
- Full width
- Light/dark mode support
- Smooth focus states with purple ring
- Proper padding and text size

### `.auth-btn-primary`
Primary call-to-action buttons.

```css
@apply w-full py-2.5 px-4 bg-slate-900 dark:bg-white text-white dark:text-slate-900 rounded-lg font-medium text-sm transition-all;
@apply hover:bg-slate-800 dark:hover:bg-slate-100 active:scale-95;
```

**Features**:
- Full width
- Inverted colors in dark mode
- Hover feedback
- Click feedback (scale-95)

### `.auth-btn-secondary`
Secondary buttons (social auth, sign out, etc.).

```css
@apply w-full py-2.5 px-4 border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white rounded-lg font-medium text-sm transition-all;
@apply hover:bg-slate-50 dark:hover:bg-slate-800 active:scale-95;
```

**Features**:
- Full width
- Bordered style
- Subtle hover states
- Click feedback (scale-95)

### `.glow-orb`
Decorative glow effects in right panel.

```css
position: absolute;
border-radius: 50%;
filter: blur(40px);
opacity: 0.3;
```

Used with gradient colors (`glow-purple`, `glow-blue`).

### `.grid-bg`
Subtle grid background pattern.

```css
background-image:
    linear-gradient(to right, rgba(15, 23, 42, 0.05) 1px, transparent 1px),
    linear-gradient(to bottom, rgba(15, 23, 42, 0.05) 1px, transparent 1px);
background-size: 40px 40px;
```

### `.float-animation`
Subtle floating animation for decorative elements.

```css
animation: float 6s ease-in-out infinite;
```

Where `float` keyframes:
- 0%, 100%: `translateY(0px) translateX(0px)`
- 50%: `translateY(-20px) translateX(10px)`

---

## Responsive Behavior

### Mobile (< 1024px)
- Single column layout
- Right decorative panel hidden
- Full-width form (with padding)
- Logo visible at top
- All spacing and text sizes optimized

### Desktop (≥ 1024px)
- Split-screen 50/50 layout
- Right panel visible with animations
- Form centered in left panel
- Larger padding (px-12 instead of px-6)
- Footer links visible at bottom

### Breakpoint: `lg:`
Used throughout for desktop-specific styling.

---

## Dark Mode

All auth pages support dark mode via Tailwind's `dark:` utilities.

**Key dark mode styles**:
- Background: `bg-white dark:bg-slate-950`
- Text: `text-slate-900 dark:text-white`
- Borders: `border-slate-200 dark:border-slate-700`
- Inputs: `dark:bg-slate-900 dark:border-slate-700`
- Buttons: Colors inverted in dark mode

Dark mode is enabled via localStorage on the guest layout.

---

## Form Validation & Errors

All auth pages use `@error()` directives for field-level validation:

```blade
@error('email')
    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
@enderror
```

**Styling**:
- Red text color
- Small font size (xs)
- Positioned below input
- Dark mode support

---

## Implementation Guidelines

### Creating New Auth Pages

1. **Extend the premium layout**:
   ```blade
   @extends('layouts.auth-premium')
   @section('title', 'Page Title')
   ```

2. **Use consistent spacing**:
   ```blade
   <div class="space-y-6">
       <!-- Content -->
   </div>
   ```

3. **Use proper heading hierarchy**:
   ```blade
   <h1 class="text-3xl font-bold tracking-tight mb-2">Main Heading</h1>
   <p class="text-sm text-slate-600 dark:text-slate-400">Subtext</p>
   ```

4. **Use auth input components** when possible:
   ```blade
   <x-auth-input name="email" label="Email" required />
   <x-auth-password-input name="password" label="Password" required />
   ```

5. **Apply form spacing**:
   ```blade
   <form method="POST" action="{{ route('form.submit') }}" class="space-y-4">
       <!-- Fields -->
   </form>
   ```

6. **Use button classes**:
   ```blade
   <button type="submit" class="auth-btn-primary">Submit</button>
   <button type="button" class="auth-btn-secondary">Secondary</button>
   ```

### Updating Controllers

When adding new auth pages:

1. Create the blade file following naming convention: `{page}-premium.blade.php`
2. Update the controller to return the premium view:
   ```php
   return view('auth.{page}-premium');
   ```
3. Document the page in this guide

---

## Browser Support

- Modern browsers with CSS Grid/Flexbox support
- Alpine.js 3.x for interactivity
- Tailwind CSS 3.x for styling
- Dark mode support via CSS `prefers-color-scheme` media query
- localStorage for dark mode preference persistence

---

## Performance Considerations

- **Font**: Uses system font fallback + Inter from CDN
- **Images**: SVG icons (no external images)
- **JavaScript**: Minimal Alpine.js for password visibility
- **CSS**: Tailwind CSS with Vite for production optimization
- **Animations**: GPU-accelerated CSS animations
- **Icons**: Inline SVG (no external dependencies)

---

## Accessibility

- Semantic HTML5 elements
- Proper heading hierarchy (h1 for page title)
- Label associations with inputs via `for` attribute
- ARIA labels on toggle buttons
- Color contrast meets WCAG AA standards
- Focus states clearly visible
- Error messages associated with inputs

---

## Future Enhancements

- [ ] Multi-step registration form
- [ ] OAuth integration (Google, GitHub)
- [ ] Passkey/WebAuthn support
- [ ] Two-factor authentication page
- [ ] Session management page
- [ ] Password strength meter
- [ ] Real-time validation feedback

---

## Related Files

- **Layout**: `resources/views/layouts/auth-premium.blade.php`
- **Pages**: `resources/views/auth/{page}-premium.blade.php`
- **Components**: `resources/views/components/auth-*.blade.php`
- **Controllers**: `app/Http/Controllers/Auth/*Controller.php`
- **Routes**: `routes/auth.php`

---

## Version History

- **v1.0** (2026-04-02): Initial premium auth design system
  - 6 core auth pages
  - Split-screen layout with decorative right panel
  - Reusable input components
  - Dark mode support
  - Responsive mobile/desktop layouts
  - Alpine.js password visibility toggles
  - Infrastructure SaaS visual aesthetic

