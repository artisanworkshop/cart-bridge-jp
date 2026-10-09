// wordpress.org 用スクリーンショットを撮る（capture.sh が呼ぶ。詳細は ../SKILL.md）。
// 使い方: node shoot.cjs <tests サイトの URL> <cookies.json> <run_id> <shots.json> <出力ディレクトリ>
// shots.json の shots[i] を screenshot-(i+1).png に撮る。管理画面にエラーの通知が出ていたら撮らずに失敗する。
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const REPO_ROOT = path.resolve( __dirname, '../../../..' );
const VIEWPORT = { width: 1280, height: 900 };

function fail( message, code = 1 ) {
	console.error( `shoot: ${ message }` );
	process.exit( code );
}

const [ baseUrl, cookiesFile, runId, shotsFile, outDir ] = process.argv.slice( 2 );
if ( ! baseUrl || ! cookiesFile || ! runId || ! shotsFile || ! outDir ) {
	fail( 'usage: node shoot.cjs <base-url> <cookies.json> <run-id> <shots.json> <out-dir>', 2 );
}
if ( ! fs.existsSync( outDir ) || ! fs.statSync( outDir ).isDirectory() ) {
	fail( `output directory does not exist: ${ outDir }`, 2 );
}

let chromium;
try {
	( { chromium } = require( require.resolve( 'playwright', { paths: [ REPO_ROOT ] } ) ) );
} catch ( e ) {
	fail( `playwright is not installed under ${ REPO_ROOT }/node_modules (run npm install): ${ e.message }`, 2 );
}

const host = new URL( baseUrl ).hostname;
const cookies = JSON.parse( fs.readFileSync( cookiesFile, 'utf8' ) ).map( ( c ) => ( {
	name: c.name,
	value: c.value,
	domain: host,
	path: c.path,
	httpOnly: true,
	secure: false,
	sameSite: 'Lax',
} ) );
const { shots } = JSON.parse( fs.readFileSync( shotsFile, 'utf8' ) );
if ( ! Array.isArray( shots ) || 0 === shots.length ) {
	fail( `no shots in ${ shotsFile }`, 2 );
}

( async () => {
	// 既定はインストール済みの Google Chrome。Playwright 同梱の Chromium を使うなら CBJP_SHOTS_CHANNEL= （空）で起動する。
	const channel = process.env.CBJP_SHOTS_CHANNEL ?? 'chrome';
	const browser = await chromium.launch( { headless: true, ...( channel ? { channel } : {} ) } );
	try {
		const context = await browser.newContext( { viewport: VIEWPORT, deviceScaleFactor: 1, locale: 'en-US' } );
		await context.addCookies( cookies );
		// Import タブは localStorage に覚えた run を開く（src/run-storage.ts の `cbjp_run_{type}_{platform}`）。
		await context.addInitScript( ( id ) => {
			try {
				window.localStorage.setItem( 'cbjp_run_dry_run_colorme', id );
			} catch ( e ) {}
		}, runId );
		const page = await context.newPage();

		for ( const [ i, shot ] of shots.entries() ) {
			const file = path.join( outDir, `screenshot-${ i + 1 }.png` );
			// ハッシュだけが違う URL への移動は SPA を読み直さないので、移動してから読み込み直す。
			await page.goto( `${ baseUrl }/wp-admin/admin.php?page=cart-bridge-jp#/${ shot.tab }` );
			await page.reload();
			if ( page.url().includes( 'wp-login.php' ) ) {
				throw new Error( 'the login cookies were not accepted (redirected to wp-login.php)' );
			}
			await page.getByText( shot.wait_for, { exact: false } ).first().waitFor( { timeout: 30000 } );
			await page.waitForLoadState( 'networkidle' );

			for ( const label of shot.uncheck ?? [] ) {
				const box = page.getByRole( 'checkbox', { name: label, exact: true } );
				if ( await box.isChecked() ) {
					await box.uncheck();
				}
			}

			const errors = await page.locator( '.notice-error, .components-notice.is-error' ).allInnerTexts();
			if ( errors.length > 0 ) {
				throw new Error( `the ${ shot.tab } tab shows an error notice: ${ errors.join( ' | ' ).slice( 0, 300 ) }` );
			}

			// フォーカスの枠とホバーの表示を写さない。
			await page.evaluate( () => document.activeElement && document.activeElement.blur() );
			await page.mouse.move( VIEWPORT.width - 10, VIEWPORT.height - 10 );
			await page.waitForTimeout( 800 );
			await page.screenshot( { path: file, fullPage: true } );
			console.log( `shot ${ path.basename( file ) } (${ shot.tab })` );
		}
	} finally {
		await browser.close();
	}
} )().catch( ( e ) => fail( e.stack || String( e ) ) );
