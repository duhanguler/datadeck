/**
 * Ultra-lightweight (<1 KB) segmented view switcher injected cleanly into native wp-admin list headers.
 */
( () => {
	const cfg = window.rowcraftToggle;
	if ( ! cfg || ! cfg.workspaceUrl ) {
		return;
	}

	const mount = () => {
		const heading = document.querySelector( '.wrap .wp-heading-inline' );
		if ( ! heading || document.getElementById( 'rowcraft-native-switcher' ) ) {
			return;
		}

		const nav = document.createElement( 'span' );
		nav.id = 'rowcraft-native-switcher';
		nav.setAttribute( 'role', 'group' );
		nav.setAttribute( 'aria-label', 'List view switcher' );
		nav.style.cssText =
			'display:inline-flex;align-items:center;margin-left:12px;vertical-align:middle;' +
			'background:#f0f0f1;border:1px solid #c3c4c7;border-radius:4px;padding:2px;font-size:12px;line-height:1;';

		const classic = document.createElement( 'span' );
		classic.textContent = cfg.classicLabel;
		classic.style.cssText =
			'padding:4px 8px;border-radius:2px;background:#fff;color:#1e1e1e;font-weight:600;' +
			'box-shadow:0 1px 1px rgba(0,0,0,0.06);';

		const modern = document.createElement( 'a' );
		modern.href = cfg.workspaceUrl;
		modern.textContent = cfg.modernLabel;
		modern.style.cssText =
			'padding:4px 8px;border-radius:2px;color:#50575e;text-decoration:none;font-weight:500;' +
			'display:inline-flex;align-items:center;gap:4px;';
		modern.addEventListener( 'mouseenter', () => {
			modern.style.color = '#1e1e1e';
		} );
		modern.addEventListener( 'mouseleave', () => {
			modern.style.color = '#50575e';
		} );

		nav.appendChild( classic );
		nav.appendChild( modern );

		const addNew = heading.nextElementSibling;
		if ( addNew && addNew.classList.contains( 'page-title-action' ) ) {
			addNew.insertAdjacentElement( 'afterend', nav );
		} else {
			heading.insertAdjacentElement( 'afterend', nav );
		}
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )();
