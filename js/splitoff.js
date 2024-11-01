document.addEventListener('DOMContentLoaded', function() {

	let count = 0;
	let closeIconPopup;
	let wrapPopup;

	wrapPopup = document.createElement('div');

	wrapPopup.style.position = 'fixed';
	wrapPopup.style.width = '100%';
	wrapPopup.style.height = '100%';
	wrapPopup.style.left = '0';
	wrapPopup.style.top = '0';
	wrapPopup.style.justifyContent = 'center';
	wrapPopup.style.alignItems = 'center';
	wrapPopup.style.background = 'rgba(0,0,0, .5)';
	wrapPopup.style.display = 'flex';
	wrapPopup.style.opacity = '0';
	wrapPopup.style.zIndex = '9999999999';

	closeIconPopup =  document.createElement('div');
	closeIconPopup.classList.add('spl-popup-close-icon')

	closeIconPopup.style.display = 'none';
	closeIconPopup.style.cursor = 'pointer';
	closeIconPopup.style.position = 'absolute';
	closeIconPopup.style.right = '30px';
	closeIconPopup.style.top = '25px';
	closeIconPopup.style.width = '40px';
	closeIconPopup.style.height = '40px';
	closeIconPopup.style.zIndex = '9999999999';

	let widthMobileDevice = window.screen.width;
	let heightMobileDevice = window.screen.height;

	if (widthMobileDevice < 660) {
		heightPopup = 100 + '%';
		widthPopup = 100 + '%';
	}


	let closePopup = (event) => {

		let popupContainer = document.querySelector('.tgp-popup--public-inner-wrap');
		let tagNamePopup = event.target.closest('div'); // (1)

		if (tagNamePopup.contains(popupContainer)) {
			closePopupMain()
		}
	}

	document.addEventListener("click", async event => {
		if (event.target.className === "splitoff-popup") {

			let htmlPageSplitOff;

			await fetch('https://togetherpay.io/pay/app/popup/en-au.html')
				.then((response) => {
					return response.text();
				})
				.then((html) => {
					htmlPageSplitOff = html;
				});

			openPopup(htmlPageSplitOff);
		}
	});


	const openPopup = (html) => {


		let templateAppendPopup = () => {

			return `
        <div
         class="tgp-popup--public-inner-wrap" >
          ${html}
        </div>`
		};

		let templateAppendCloseIconPopup = `
        <svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path fill-rule="evenodd" clip-rule="evenodd" d="M10.4882 10.4882C9.83728 11.139 9.83728 12.1943 10.4882 12.8452L27.1548 29.5118C27.8057 30.1627 28.861 30.1627 29.5118 29.5118C30.1627 28.861 30.1627 27.8057 29.5118 27.1548L12.8452 10.4882C12.1943 9.83728 11.139 9.83728 10.4882 10.4882Z" fill="#fff"></path>
        <path fill-rule="evenodd" clip-rule="evenodd" d="M29.5118 10.4882C30.1627 11.139 30.1627 12.1943 29.5118 12.8452L12.8452 29.5118C12.1943 30.1627 11.139 30.1627 10.4882 29.5118C9.83728 28.861 9.83728 27.8057 10.4882 27.1548L27.1548 10.4882C27.8057 9.83728 28.861 9.83728 29.5118 10.4882Z" fill="#fff"></path>
        </svg>
       `;

		count++;

		if ( count > 1 ) {
			return  false
		}


		else {
			wrapPopup.classList.add('tgp-popup--puplic-wrap');
			document.querySelector('body').appendChild(wrapPopup);

			wrapPopup.insertAdjacentHTML('beforeEnd' , templateAppendPopup());


			let popupContainerWrap = document.querySelector('.tgp-popup--puplic-wrap');
			let popupContainerInnerWrap = document.querySelector('.tgp-popup--public-inner-wrap');

			unfade(popupContainerWrap);
			unfade(popupContainerInnerWrap);


			closeIconPopup.insertAdjacentHTML('beforeEnd',templateAppendCloseIconPopup);

			closeIconPopup.style.display = 'flex';
			document.querySelector('.tgp-popup--public-inner-wrap').appendChild(closeIconPopup);
		}

	};


	const closePopupMain = () => {
		let popupContainerWrap = document.querySelector('.tgp-popup--puplic-wrap');
		let popupContainerInnerWrap = document.querySelector('.tgp-popup--public-inner-wrap');
		fade(popupContainerInnerWrap)
		fade(popupContainerWrap)

		setTimeout( () => {
			document.querySelector('.tgp-popup--puplic-wrap .tgp-popup--public-inner-wrap').remove();
			document.querySelector('.tgp-popup--puplic-wrap').remove();
			closeIconPopup.firstElementChild.remove();
			closeIconPopup.remove();
		}, 400)

		count = 0;

	}

	function fade(element) {
		var op = 1;  // initial opacity
		var timer = setInterval(function () {
			if (op <= 0.1){
				clearInterval(timer);
				element.style.display = 'none';
			}
			element.style.opacity = op;
			element.style.filter = 'alpha(opacity=' + op * 100 + ")";
			op -= op * 0.1;
		}, 10);
	}


	function unfade(element) {
		var op = 0.1;  // initial opacity
		element.style.display = 'flex';
		var timer = setInterval(function () {
			if (op >= 1){
				clearInterval(timer);
			}
			element.style.opacity = op;
			element.style.filter = 'alpha(opacity=' + op * 100 + ")";
			op += op * 0.1;
		}, 10);
	}

	closeIconPopup.addEventListener('click', closePopupMain);
	document.addEventListener('click', closePopup);

});
