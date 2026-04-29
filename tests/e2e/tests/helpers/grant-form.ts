/**
 * Page object for the grant-access form rendered by Form::print_auth_screen.
 *
 * Centralises the locators so a class rename in trustedlogin.css or a
 * markup change in Form.php breaks one place, not five spec files.
 */

import type { Page, Locator } from '@playwright/test';
import { CLIENT_URL } from './login';

export const NS = 'pro-block-builder';

export class GrantForm {
	constructor(
		private page: Page,
		private baseUrl: string = CLIENT_URL,
	) {}

	get path(): string {
		return `/wp-admin/admin.php?page=grant-${ NS }-access`;
	}

	async navigate(): Promise<void> {
		await this.page.goto( this.baseUrl + this.path, { waitUntil: 'domcontentloaded' } );
	}

	grantButton(): Locator {
		return this.page.locator( '.tl-client-grant-button' );
	}

	revokeButton(): Locator {
		return this.page.locator( '.tl-client-revoke-button' );
	}

	statusBanner(): Locator {
		return this.page.locator( `.tl-${ NS }-auth__response` );
	}

	accessKeyInput(): Locator {
		return this.page.locator( `#tl-${ NS }-access-key` );
	}

	/**
	 * The status div carries an additional class indicating type:
	 *   tl-{ns}-auth__response_pending
	 *   tl-{ns}-auth__response_success
	 *   tl-{ns}-auth__response_error
	 *
	 * Use these matchers to wait for the JS state machine to settle
	 * after a click.
	 */
	statusOfType( type: 'pending' | 'success' | 'error' ): Locator {
		return this.page.locator( `.tl-${ NS }-auth__response_${ type }` );
	}
}
