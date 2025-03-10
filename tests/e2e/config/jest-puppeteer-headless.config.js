const { jestPuppeteerConfig } = require( '@woocommerce/e2e-environment' );

// Add arg to allow accessing the payments iframes in interactive mode ({ headles: false }).
// https://github.com/puppeteer/puppeteer/issues/4960#issuecomment-535729011
jestPuppeteerConfig.launch.args.push( '--disable-features=site-per-process' );
jestPuppeteerConfig.launch.args.push( '--disable-web-security' );
jestPuppeteerConfig.launch.args.push( '--disable-features=IsolateOrigins' );
jestPuppeteerConfig.launch.args.push( '--disable-site-isolation-trials' );

// Set a real User Agent so the "Add block" button isn't disabled in Gutenberg during -dev tests.
// Also keeping the "puppeteer-debug" value coming from @automattic.puppeteer
jestPuppeteerConfig.launch.args.push(
	// eslint-disable-next-line max-len
	'--user-agent=Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.55 Safari/537.36 puppeteer-debug'
);

jestPuppeteerConfig.launch.headless = 'new';

module.exports = jestPuppeteerConfig;
