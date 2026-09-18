"use strict";(()=>{var h=class{constructor(e){this.config=e}inspect(e,t){return this.post("inspect",e,t)}search(e,t){return this.post("search",e,t)}async post(e,t,r){let n=this.config.restUrl.replace(/\/$/,"")+"/"+e,i=await fetch(n,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-WP-Nonce":this.config.nonce},body:JSON.stringify({traceId:this.config.traceId,element:t}),signal:r}),o=null;try{o=await i.json()}catch{o=null}if(!i.ok){let s=o&&typeof o=="object"&&"message"in o?String(o.message):`HTTP ${i.status}`;throw new Error(s)}return o}};var u=class{constructor(e){this.hoverTarget=null;this.selectedTarget=null;this.hover=document.createElement("div"),this.hover.className="edittrace-highlight edittrace-highlight--hover",this.hover.hidden=!0,this.hoverBadge=document.createElement("span"),this.hoverBadge.className="edittrace-badge",this.hover.appendChild(this.hoverBadge),this.selected=document.createElement("div"),this.selected.className="edittrace-highlight edittrace-highlight--selected",this.selected.hidden=!0,this.selectedBadge=document.createElement("span"),this.selectedBadge.className="edittrace-badge",this.selected.appendChild(this.selectedBadge),e.appendChild(this.hover),e.appendChild(this.selected)}setHover(e,t=""){this.hoverTarget=e,this.hoverBadge.textContent=t,this.draw(this.hover,e)}setSelected(e,t=""){this.selectedTarget=e,this.selectedBadge.textContent=t,this.draw(this.selected,e)}getSelected(){return this.selectedTarget}getHover(){return this.hoverTarget}refresh(){this.draw(this.hover,this.hoverTarget),this.draw(this.selected,this.selectedTarget)}clear(){this.setHover(null),this.setSelected(null)}draw(e,t){if(!t||!t.isConnected){e.hidden=!0;return}let r=t.getBoundingClientRect();e.hidden=!1,e.style.left=`${r.left}px`,e.style.top=`${r.top}px`,e.style.width=`${Math.max(r.width,0)}px`,e.style.height=`${Math.max(r.height,0)}px`,e.classList.toggle("edittrace-highlight--badge-below",r.top<28)}};function c(a){return String(a??"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#039;")}function l(a){return c(a)}function d(a){return a?/^(https?:)?\/\//i.test(a)||a.startsWith("/"):!1}function w(a,e=90){return a.length>e?a.slice(0,e-1)+"\u2026":a}var g=class{constructor(e,t,r){this.config=t;this.callbacks=r;this.state={status:"idle"};this.element=document.createElement("aside"),this.element.className="edittrace-panel",this.element.setAttribute("role","complementary"),this.element.setAttribute("aria-label","EditTrace"),this.element.hidden=!0,e.appendChild(this.element),this.element.addEventListener("click",n=>this.handleClick(n))}getState(){return this.state}setState(e){this.state=e,this.element.hidden=e.status==="idle",this.element.innerHTML=this.render(e),e.status!=="idle"&&(this.element.scrollTop=0)}isOpen(){return this.state.status!=="idle"}close(){this.setState({status:"idle"})}handleClick(e){let t=e.target.closest("[data-edittrace-action]");if(!t)return;let r=t.getAttribute("data-edittrace-action");r==="close"?(e.preventDefault(),this.callbacks.onClose()):r==="parent"?(e.preventDefault(),this.callbacks.onInspectParent()):r==="search"&&(e.preventDefault(),this.callbacks.onSearch())}render(e){let t=`
			<header class="edittrace-panel__header">
				<span class="edittrace-panel__brand"><span class="edittrace-panel__dot"></span>EditTrace</span>
				<button type="button" class="edittrace-iconbtn" data-edittrace-action="close" aria-label="Close panel" title="Close (Esc)">\xD7</button>
			</header>`;switch(e.status){case"idle":return"";case"loading":return`${t}
					<div class="edittrace-panel__body">
						${this.selectedBlock(e.summary)}
						<div class="edittrace-loading"><span class="edittrace-spinner"></span>${c(this.config.i18n.loading||"Tracing source\u2026")}</div>
					</div>`;case"error":return`${t}
					<div class="edittrace-panel__body">
						${this.selectedBlock(e.summary)}
						<div class="edittrace-alert edittrace-alert--error">${c(e.message)}</div>
					</div>`;case"result":return`${t}<div class="edittrace-panel__body">${this.renderResult(e.result,e.searching,e.searchError)}</div>`}}selectedBlock(e,t=""){return`
			<section class="edittrace-section edittrace-section--selected">
				<div class="edittrace-label">Selected element</div>
				<div class="edittrace-selected">
					${t?`<span class="edittrace-tag">${c(t)}</span>`:""}
					<span class="edittrace-selected__text">${c(w(e,140))}</span>
				</div>
			</section>`}renderResult(e,t,r){let n=[];if(n.push(this.selectedBlock(e.selected.summary,e.selected.tag)),e.status==="exact"&&e.primary){n.push(this.renderPrimary(e.primary));let i=e.candidates.slice(1);i.length&&n.push(this.renderOthers(i,"Also involved"))}else e.status==="candidates"?(n.push(`
				<section class="edittrace-section">
					<div class="edittrace-headline edittrace-headline--warn">Exact source not found</div>
					<div class="edittrace-muted">${e.candidates.length} possible source${e.candidates.length===1?"":"s"}${e.searched?" from WordPress search":""}</div>
				</section>`),n.push(this.renderCandidateList(e.candidates))):n.push(this.renderUnknown(e,t,r));return t&&e.status!=="unknown"&&n.push('<div class="edittrace-loading"><span class="edittrace-spinner"></span>Searching WordPress\u2026</div>'),n.push(this.renderTechnical(e)),n.join("")}renderPrimary(e){let t=[];t.push(`<div class="edittrace-system">${c(e.system||e.provider)}</div>`),e.sourceName&&t.push(this.kv(e.sourceLabel||"Source",e.sourceName)),e.itemName&&t.push(this.kv(e.itemLabel||"Item",e.itemName,e.itemKey&&e.itemKey!==e.itemName?e.itemKey:""));for(let[n,i]of Object.entries(e.details||{}))t.push(this.kv(n,i));let r=`
			<section class="edittrace-section">
				<div class="edittrace-label">Source</div>
				${t.join("")}
			</section>`;return e.hierarchy&&e.hierarchy.length>1&&(r+=`
				<section class="edittrace-section">
					<div class="edittrace-label">Location</div>
					<ol class="edittrace-crumbs">${e.hierarchy.map((n,i)=>`<li class="edittrace-crumb${i===e.hierarchy.length-1?" edittrace-crumb--current":""}">${c(n)}</li>`).join("")}</ol>
				</section>`),e.global&&(r+=`
				<section class="edittrace-section">
					<div class="edittrace-global"><div class="edittrace-global__title">Global content</div><div>${c(e.globalNote||"Changes here may affect multiple pages.")}</div></div>
				</section>`),e.usage&&Object.keys(e.usage).length&&(r+=`
				<section class="edittrace-section">
					<div class="edittrace-label">Usage</div>
					${Object.entries(e.usage).map(([n,i])=>this.kv(n,String(i))).join("")}
				</section>`),r+=this.renderActions(e),r+=`
			<section class="edittrace-section">
				<div class="edittrace-label">Confidence</div>
				${this.confidenceBadge(e)}
				${e.reason?`<div class="edittrace-muted edittrace-reason">${c(e.reason)}</div>`:""}
			</section>`,r}renderActions(e){let t=[];d(e.editUrl)&&t.push(`<a class="edittrace-btn edittrace-btn--primary" href="${l(e.editUrl)}" target="_blank" rel="noopener">${c(e.editLabel||"Edit")}</a>`);for(let r of e.actions||[])d(r.url)&&t.push(`<a class="edittrace-btn" href="${l(r.url)}" target="_blank" rel="noopener">${c(r.label)}</a>`);return t.length||t.push('<div class="edittrace-muted">No direct edit link is available for this source.</div>'),`
			<section class="edittrace-section">
				<div class="edittrace-label">Actions</div>
				<div class="edittrace-actions">${t.join("")}</div>
			</section>`}renderOthers(e,t){return`
			<section class="edittrace-section">
				<div class="edittrace-label">${c(t)}</div>
				${this.renderCandidateList(e)}
			</section>`}renderCandidateList(e){let t=e.slice(0,3),r=e.slice(3),n=o=>`
			<li class="edittrace-candidate">
				<div class="edittrace-candidate__head">
					<span class="edittrace-candidate__name">${c(o.sourceName||o.itemName||o.system)}</span>
					${this.confidenceBadge(o)}
				</div>
				<div class="edittrace-candidate__meta">${c([o.system,o.sourceLabel,o.itemLabel&&o.itemName?`${o.itemLabel}: ${o.itemName}`:o.itemName].filter(Boolean).join(" \xB7 "))}</div>
				${o.global?'<div class="edittrace-candidate__global">Global content</div>':""}
				${o.reason?`<div class="edittrace-candidate__reason">${c(o.reason)}</div>`:""}
				<div class="edittrace-candidate__actions">
					${d(o.editUrl)?`<a class="edittrace-btn edittrace-btn--small" href="${l(o.editUrl)}" target="_blank" rel="noopener">${c(o.editLabel||"Edit")}</a>`:""}
					${(o.actions||[]).filter(s=>d(s.url)).map(s=>`<a class="edittrace-btn edittrace-btn--small edittrace-btn--ghost" href="${l(s.url)}" target="_blank" rel="noopener">${c(s.label)}</a>`).join("")}
				</div>
			</li>`,i=`<ol class="edittrace-candidates">${t.map(n).join("")}</ol>`;return r.length&&(i+=`<details class="edittrace-details"><summary>${r.length} more</summary><ol class="edittrace-candidates" start="4">${r.map(n).join("")}</ol></details>`),i}renderUnknown(e,t,r){let n=this.config.fallbackSearch&&!e.searched;return`
			<section class="edittrace-section">
				<div class="edittrace-headline edittrace-headline--warn">Source not identified</div>
				<p class="edittrace-muted">EditTrace could not determine the exact source of this element${e.searched?", and a WordPress search found nothing usable":""}.</p>
				${t?'<div class="edittrace-loading"><span class="edittrace-spinner"></span>Searching WordPress\u2026</div>':""}
				${r?`<div class="edittrace-alert edittrace-alert--error">${c(r)}</div>`:""}
				<div class="edittrace-label">Try</div>
				<div class="edittrace-actions">
					<button type="button" class="edittrace-btn" data-edittrace-action="parent">Inspect parent</button>
					${n&&!t?`<button type="button" class="edittrace-btn" data-edittrace-action="search">${c(this.config.i18n.search||"Search WordPress")}</button>`:""}
					${d(e.page.editUrl)?`<a class="edittrace-btn edittrace-btn--ghost" href="${l(e.page.editUrl)}" target="_blank" rel="noopener">Open current page in editor</a>`:""}
				</div>
			</section>`}renderTechnical(e){let t=[];t.push(this.kv("Current page",e.page.title||"\u2014")),e.page.template&&t.push(this.kv("Template",e.page.template)),e.page.postType&&t.push(this.kv("Queried object",`${e.page.postType} #${e.page.objectId}`)),t.push(this.kv("Page trace",e.trace.available?`available (${e.trace.entries} entries)`:"not available")),e.debug&&e.trace.id&&t.push(this.kv("Trace id",e.trace.id));let r=[...e.candidates,...e.weak||[]];for(let n of r){let i=Object.entries(n.technical||{}).filter(([o])=>e.debug||o!=="providerPriority").map(([o,s])=>`${o}: ${typeof s=="object"?JSON.stringify(s):String(s)}`).join(", ");t.push(this.kv(`${n.provider} (${n.confidence.toFixed(2)})`,`${n.sourceType}${n.sourceId?" "+n.sourceId:""}${i?" \u2014 "+i:""}`))}r.length||t.push(this.kv("Providers","no candidates"));for(let n of e.notes||[])t.push(`<div class="edittrace-note">${c(n)}</div>`);return e.selected.href&&t.push(this.kv("href",e.selected.href)),e.selected.src&&t.push(this.kv("src",e.selected.src)),`
			<details class="edittrace-details edittrace-technical">
				<summary>Technical details</summary>
				<div class="edittrace-technical__body">${t.join("")}</div>
			</details>`}confidenceBadge(e){return`<span class="edittrace-confidence edittrace-confidence--${l(e.status)}" title="Score ${e.confidence.toFixed(2)}">${c(e.statusLabel)}</span>`}kv(e,t,r=""){return`<div class="edittrace-kv"><span class="edittrace-kv__k">${c(e)}</span><span class="edittrace-kv__v">${c(t)}${r?`<span class="edittrace-kv__sub">${c(r)}</span>`:""}</span></div>`}};var R=new Set(["input","textarea","select","option","script","style","template","noscript"]);function k(a){return R.has(a.tagName.toLowerCase())}function I(a){if(k(a)){if(a.tagName.toLowerCase()==="input"){let o=(a.getAttribute("type")||"text").toLowerCase();if(o==="submit"||o==="button"||o==="reset")return(a.getAttribute("value")||"").trim()}return""}let e=[],t=document.createTreeWalker(a,NodeFilter.SHOW_TEXT|NodeFilter.SHOW_ELEMENT,{acceptNode(i){return i.nodeType===Node.ELEMENT_NODE?k(i)?NodeFilter.FILTER_REJECT:NodeFilter.FILTER_SKIP:NodeFilter.FILTER_ACCEPT}}),r=t.nextNode(),n=0;for(;r&&n<1e3*2;){let i=r.nodeValue||"";e.push(i),n+=i.length,r=t.nextNode()}return e.join(" ").replace(/\s+/g," ").trim().slice(0,1e3)}function D(a){let e=new Set;for(let t of a)(t.datasetKeys||[]).forEach(r=>e.add(r)),t.typeFromDataset&&e.add(t.typeFromDataset);return e}function S(a,e){let t={},r=a.dataset;if(!r)return t;for(let n of Object.keys(r))if(n.startsWith("edittrace")||e.has(n)){let i=r[n];typeof i=="string"&&i.length<=200&&(t[n]=i)}return t}function _(a){return Array.from(a.classList).slice(0,40)}function B(a,e){return{tag:a.tagName.toLowerCase(),id:a.id||"",classes:_(a),dataset:S(a,e)}}function F(a){let e=null,t=a.tagName.toLowerCase();if(t==="img"?e=a:(t==="picture"||t==="figure")&&(e=a.querySelector("img")),!e)return{src:"",alt:"",width:0,height:0};let r=e.currentSrc||e.getAttribute("src")||e.getAttribute("data-src")||"";return{src:r.startsWith("data:")?"":r,alt:(e.getAttribute("alt")||"").trim(),width:e.naturalWidth||0,height:e.naturalHeight||0}}function v(a,e,t){let r=D(e),n=a.tagName.toLowerCase(),i=F(a),o="";(n==="a"||n==="area")&&(o=a.href||"");let s=[],p=a.parentElement;for(;p&&s.length<10&&p!==document.documentElement;)s.push(B(p,r)),p=p.parentElement;return{tag:n,text:I(a),href:o,src:i.src,alt:i.alt,width:i.width,height:i.height,id:a.id||"",classes:_(a),dataset:S(a,r),ancestors:s,pageUrl:window.location.href.split("#")[0],traceId:t}}var z=new Set(["span","strong","em","b","i","u","small","mark","sub","sup","code","abbr","time","s","del","ins","br","wbr","font","bdi","bdo","kbd","samp","var","cite","q","dfn"]),j=new Set(["a","button","h1","h2","h3","h4","h5","h6","p","li","label","td","th","blockquote","figcaption","dt","dd","summary","legend","pre"]),O=new Set(["a","button","h1","h2","h3","h4","h5","h6","p","li","ul","ol","img","picture","video","audio","figure","nav","header","footer","main","section","article","aside","form","table","blockquote","iframe","svg","input","select","textarea","label","details","summary"]),q=new Set(["script","style","template","noscript","link","meta"]),U={h1:"Heading",h2:"Heading",h3:"Heading",h4:"Heading",h5:"Heading",h6:"Heading",p:"Paragraph",a:"Link",button:"Button",img:"Image",picture:"Image",svg:"Icon",video:"Video",audio:"Audio",ul:"List",ol:"List",li:"List item",nav:"Navigation",header:"Header",footer:"Footer",main:"Main",section:"Section",article:"Article",aside:"Sidebar",form:"Form",table:"Table",tr:"Table row",td:"Table cell",th:"Table cell",blockquote:"Quote",figure:"Figure",figcaption:"Caption",iframe:"Embed",input:"Field",select:"Field",textarea:"Field",label:"Label",span:"Text",strong:"Text",em:"Text",div:"Container",details:"Details",summary:"Summary",time:"Text"};function C(a){return z.has(a.tagName.toLowerCase())}function $(a){return O.has(a.tagName.toLowerCase())}function X(a){return q.has(a.tagName.toLowerCase())}function x(a){let e=a.tagName.toLowerCase();if(e==="button")return!0;if(e==="input"){let r=(a.getAttribute("type")||"text").toLowerCase();return r==="submit"||r==="button"||r==="reset"}if(a.getAttribute("role")==="button")return!0;let t=a.className&&typeof a.className=="string"?a.className.toLowerCase():"";return/(^|[\s_-])(button|btn)([\s_-]|$)/.test(t)}function W(a){let e=a.tagName.toLowerCase();return e==="a"&&x(a)||e==="input"&&x(a)?"Button":U[e]||"Element"}function K(a,e){let t=a;for(;t&&t!==document.documentElement;){for(let r of e)try{if(t.matches(r.selector))return{element:t,marker:r}}catch{}t=t.parentElement}return null}function m(a,e){return e.some(t=>{try{return a.matches(t.selector)}catch{return!1}})}function T(a){return a.replace(/\.default$/,"").replace(/^core\//,"").replace(/^[a-z0-9-]+\//,"").split(/[-_./]/).filter(Boolean).map(e=>e.charAt(0).toUpperCase()+e.slice(1)).join(" ")}function G(a,e){var t;if(e.typeFromDataset){let r=(t=a.dataset)==null?void 0:t[e.typeFromDataset];if(r)return T(r)}if(e.typeFromClassPrefix){for(let r of Array.from(a.classList))if(r.startsWith(e.typeFromClassPrefix)&&r.length>e.typeFromClassPrefix.length){let n=r.slice(e.typeFromClassPrefix.length);if(!/__|--/.test(n))return T(n)}}return""}function y(a,e){let t=K(a,e),r=W(a);if(!t)return r;let n=t.element===a&&G(a,t.marker)||r;return`${t.marker.label} \xB7 ${n}`}function b(a){return a.getBoundingClientRect()}function N(a,e,t=3){let r=b(a),n=b(e);return Math.abs(r.left-n.left)<=t&&Math.abs(r.top-n.top)<=t&&Math.abs(r.width-n.width)<=t&&Math.abs(r.height-n.height)<=t}function E(a,e){var n;let t=a,r=t.closest("svg");if(r&&r!==t&&(t=r),m(t,e))return t;if(C(t)){let i=t.parentElement;for(;i&&i!==document.body;){let o=i.tagName.toLowerCase();if(j.has(o)||m(i,e)||x(i))return i;if(!C(i))break;i=i.parentElement}}if(t.tagName.toLowerCase()==="source"&&((n=t.parentElement)==null?void 0:n.tagName.toLowerCase())==="picture"){let i=t.parentElement.querySelector("img");if(i)return i}return t}function M(a,e){let t=a.parentElement;for(;t&&t!==document.documentElement;){if(t===document.body)return null;if(m(t,e)||$(t)||!N(t,a))return t;t=t.parentElement}return null}function L(a){if(X(a))return!1;let e=b(a);return e.width>0&&e.height>0}function A(a,e,t){let r=Array.from(a.children).filter(L);if(r.length===0)return null;let n;t&&(n=r.find(o=>{let s=b(o);return t.x>=s.left&&t.x<=s.right&&t.y>=s.top&&t.y<=s.bottom}));let i=n||r[0];for(let o=0;o<6&&!(m(i,e)||$(i));o++){let s=Array.from(i.children).filter(L);if(s.length!==1||!N(s[0],i))break;i=s[0]}return i}var f=class{constructor(e,t){this.config=e;this.active=!1;this.lastPoint=null;this.raf=0;this.pendingHover=null;this.controller=null;this.requestId=0;this.api=new h(e),this.host=document.createElement("div"),this.host.id="edittrace-root",this.host.setAttribute("data-edittrace-ui",""),this.shadow=this.host.attachShadow({mode:"open"});let r=document.createElement("style");r.textContent=t,this.shadow.appendChild(r),this.highlighter=new u(this.shadow),this.toolbar=this.buildToolbar(),this.shadow.appendChild(this.toolbar),this.panel=new g(this.shadow,e,{onClose:()=>this.closePanel(),onInspectParent:()=>this.selectParent(),onSearch:()=>this.runSearch()}),this.globalStyle=document.createElement("style"),this.globalStyle.id="edittrace-global-style",this.globalStyle.textContent="html.edittrace-inspecting, html.edittrace-inspecting body, html.edittrace-inspecting body *:not([data-edittrace-ui]) { cursor: crosshair !important; }",this.onMouseMove=this.onMouseMove.bind(this),this.onClick=this.onClick.bind(this),this.onKeyDown=this.onKeyDown.bind(this),this.onScroll=this.onScroll.bind(this)}isActive(){return this.active}toggle(){this.active?this.deactivate():this.activate()}activate(){this.active||(this.active=!0,this.host.isConnected||document.body.appendChild(this.host),document.head.appendChild(this.globalStyle),document.documentElement.classList.add("edittrace-inspecting"),this.host.classList.add("edittrace-root--active"),this.toolbar.hidden=!1,document.addEventListener("mousemove",this.onMouseMove,!0),document.addEventListener("click",this.onClick,!0),document.addEventListener("keydown",this.onKeyDown,!0),window.addEventListener("scroll",this.onScroll,!0),window.addEventListener("resize",this.onScroll),document.dispatchEvent(new CustomEvent("edittrace:activate")))}deactivate(){this.active&&(this.active=!1,this.cancelRequest(),document.documentElement.classList.remove("edittrace-inspecting"),this.globalStyle.remove(),this.host.classList.remove("edittrace-root--active"),this.toolbar.hidden=!0,this.highlighter.clear(),this.panel.close(),document.removeEventListener("mousemove",this.onMouseMove,!0),document.removeEventListener("click",this.onClick,!0),document.removeEventListener("keydown",this.onKeyDown,!0),window.removeEventListener("scroll",this.onScroll,!0),window.removeEventListener("resize",this.onScroll),document.dispatchEvent(new CustomEvent("edittrace:deactivate")))}getSelected(){return this.highlighter.getSelected()}async select(e){return this.highlighter.setSelected(e,y(e,this.config.markers)),this.highlighter.setHover(null),this.inspect(e)}isOwnNode(e){var r;return e instanceof Node?e.getRootNode()===this.shadow||this.host.contains(e)||!!((r=e.closest)!=null&&r.call(e,"#wpadminbar")):!1}onMouseMove(e){if(this.isOwnNode(e.target)){this.pendingHover=null,this.scheduleHover();return}this.lastPoint={x:e.clientX,y:e.clientY};let t=e.target instanceof Element?E(e.target,this.config.markers):null;this.pendingHover=t,this.scheduleHover()}scheduleHover(){this.raf||(this.raf=window.requestAnimationFrame(()=>{this.raf=0;let e=this.pendingHover;e&&e!==this.highlighter.getSelected()?this.highlighter.setHover(e,y(e,this.config.markers)):this.highlighter.setHover(null)}))}onClick(e){if(this.isOwnNode(e.target)||(e.preventDefault(),e.stopPropagation(),e.stopImmediatePropagation(),!(e.target instanceof Element)))return;this.lastPoint={x:e.clientX,y:e.clientY};let t=E(e.target,this.config.markers);this.select(t)}onKeyDown(e){if(!this.active)return;let t=e.target;if(!(t&&!this.isOwnNode(t)&&/^(input|textarea|select)$/i.test(t.tagName)&&e.key!=="Escape"))switch(e.key){case"Escape":e.preventDefault(),e.stopPropagation(),this.panel.isOpen()?this.closePanel():this.deactivate();break;case"ArrowUp":e.preventDefault(),e.stopPropagation(),this.selectParent();break;case"ArrowDown":e.preventDefault(),e.stopPropagation(),this.selectChild();break;case"Enter":{let n=this.highlighter.getSelected()||this.highlighter.getHover();n&&(e.preventDefault(),e.stopPropagation(),this.select(n));break}default:break}}onScroll(){this.highlighter.refresh()}current(){return this.highlighter.getSelected()||this.highlighter.getHover()}selectParent(){let e=this.current();if(!e)return;let t=M(e,this.config.markers);t&&this.select(t)}selectChild(){let e=this.current();if(!e)return;let t=A(e,this.config.markers,this.lastPoint||void 0);t&&this.select(t)}closePanel(){this.cancelRequest(),this.panel.close(),this.highlighter.setSelected(null)}cancelRequest(){this.controller&&(this.controller.abort(),this.controller=null)}async inspect(e){this.cancelRequest();let t=++this.requestId,r=v(e,this.config.markers,this.config.traceId),n=r.text||r.alt||r.href||r.src||`<${r.tag}>`;this.panel.setState({status:"loading",summary:n}),this.controller=new AbortController;try{let i=await this.api.inspect(r,this.controller.signal);return t!==this.requestId?null:(this.panel.setState({status:"result",result:i,searching:!1}),document.dispatchEvent(new CustomEvent("edittrace:result",{detail:i})),i.status==="unknown"&&this.config.fallbackSearch&&this.runSearch(),i)}catch(i){return i.name==="AbortError"||t!==this.requestId||this.panel.setState({status:"error",message:i.message||this.config.i18n.error||"Request failed",summary:n}),null}}async runSearch(){let e=this.panel.getState(),t=this.highlighter.getSelected();if(e.status!=="result"||!t)return;let r=this.requestId;this.panel.setState({...e,searching:!0,searchError:void 0});let n=v(t,this.config.markers,this.config.traceId);this.controller=new AbortController;try{let i=await this.api.search(n,this.controller.signal);if(r!==this.requestId)return;let o=e.result,s={...o,status:i.candidates.length?"candidates":"unknown",primary:i.primary,candidates:i.candidates,weak:[...o.weak||[],...i.weak||[]],searched:!0,notes:[...o.notes||[],...i.notes||[]]};this.panel.setState({status:"result",result:s,searching:!1}),document.dispatchEvent(new CustomEvent("edittrace:result",{detail:s}))}catch(i){if(i.name==="AbortError"||r!==this.requestId)return;this.panel.setState({...e,searching:!1,searchError:i.message})}}buildToolbar(){let e=document.createElement("div");e.className="edittrace-toolbar",e.setAttribute("role","toolbar"),e.setAttribute("aria-label","EditTrace inspector"),e.hidden=!0;let t=this.config.i18n;return e.innerHTML=`
			<span class="edittrace-toolbar__status"><span class="edittrace-toolbar__dot"></span>${t.active||"EditTrace Active"}</span>
			<button type="button" class="edittrace-toolbar__btn" data-edittrace-tool="parent" title="Select parent (\u2191)"><span aria-hidden="true">\u2191</span> ${t.parent||"Parent"}</button>
			<button type="button" class="edittrace-toolbar__btn" data-edittrace-tool="child" title="Select child (\u2193)"><span aria-hidden="true">\u2193</span> ${t.child||"Child"}</button>
			<button type="button" class="edittrace-toolbar__btn edittrace-toolbar__btn--exit" data-edittrace-tool="exit" title="Exit (Esc)">${t.exit||"Exit"}</button>`,e.addEventListener("click",r=>{let n=r.target.closest("[data-edittrace-tool]");if(!n)return;let i=n.getAttribute("data-edittrace-tool");i==="parent"?this.selectParent():i==="child"?this.selectChild():i==="exit"&&this.deactivate()}),e}};var P=`:host {
	all: initial;
	--et-bg: #16181d;
	--et-bg-2: #1e2128;
	--et-bg-3: #262a33;
	--et-border: #2f343e;
	--et-text: #e6e8ec;
	--et-text-2: #a2a8b4;
	--et-text-3: #737a87;
	--et-accent: #4f8cff;
	--et-accent-2: #7cb1ff;
	--et-exact: #33c481;
	--et-high: #4f8cff;
	--et-possible: #f2b84b;
	--et-unknown: #8a8f9a;
	--et-danger: #ff6b6b;
	--et-warn: #f2b84b;
	--et-radius: 8px;
	--et-font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Inter, Helvetica, Arial, sans-serif;
	--et-mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
	font-family: var(--et-font);
	font-size: 13px;
	line-height: 1.45;
	color: var(--et-text);
}

*, *::before, *::after {
	box-sizing: border-box;
}

[hidden] {
	display: none !important;
}

/* Highlighter --------------------------------------------------------- */
.edittrace-highlight {
	position: fixed;
	z-index: 2147483000;
	pointer-events: none;
	border: 2px solid var(--et-accent);
	border-radius: 2px;
	box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.25), inset 0 0 0 1px rgba(255, 255, 255, 0.15);
	background: rgba(79, 140, 255, 0.08);
	transition: left 60ms ease-out, top 60ms ease-out, width 60ms ease-out, height 60ms ease-out;
}

.edittrace-highlight--hover {
	border-style: dashed;
	background: rgba(79, 140, 255, 0.05);
}

.edittrace-highlight--selected {
	border-color: var(--et-exact);
	background: rgba(51, 196, 129, 0.08);
}

.edittrace-badge {
	position: absolute;
	left: -2px;
	top: -24px;
	max-width: 320px;
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;
	padding: 2px 8px;
	font: 600 11px/18px var(--et-font);
	letter-spacing: 0.01em;
	color: #fff;
	background: var(--et-accent);
	border-radius: 4px 4px 0 0;
}

.edittrace-highlight--selected .edittrace-badge {
	background: var(--et-exact);
	color: #06281a;
}

.edittrace-highlight--badge-below .edittrace-badge {
	top: auto;
	bottom: -24px;
	border-radius: 0 0 4px 4px;
}

.edittrace-badge:empty {
	display: none;
}

/* Toolbar ------------------------------------------------------------- */
.edittrace-toolbar {
	position: fixed;
	z-index: 2147483001;
	left: 50%;
	bottom: 16px;
	transform: translateX(-50%);
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 6px 8px;
	background: var(--et-bg);
	border: 1px solid var(--et-border);
	border-radius: 999px;
	box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
	color: var(--et-text);
	white-space: nowrap;
}

.edittrace-toolbar__status {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 0 10px 0 6px;
	font-weight: 600;
	font-size: 12px;
}

.edittrace-toolbar__dot, .edittrace-panel__dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: var(--et-exact);
	box-shadow: 0 0 0 3px rgba(51, 196, 129, 0.2);
}

.edittrace-toolbar__btn {
	appearance: none;
	border: 1px solid transparent;
	background: transparent;
	color: var(--et-text-2);
	font: 500 12px/1 var(--et-font);
	padding: 7px 10px;
	border-radius: 999px;
	cursor: pointer;
}

.edittrace-toolbar__btn:hover, .edittrace-toolbar__btn:focus-visible {
	background: var(--et-bg-3);
	color: var(--et-text);
	outline: none;
}

.edittrace-toolbar__btn--exit {
	color: var(--et-text);
	border-color: var(--et-border);
}

/* Panel --------------------------------------------------------------- */
.edittrace-panel {
	position: fixed;
	z-index: 2147483002;
	top: 0;
	right: 0;
	bottom: 0;
	width: 400px;
	max-width: 100vw;
	overflow-y: auto;
	overscroll-behavior: contain;
	background: var(--et-bg);
	color: var(--et-text);
	border-left: 1px solid var(--et-border);
	box-shadow: -12px 0 40px rgba(0, 0, 0, 0.35);
	font-family: var(--et-font);
	font-size: 13px;
}

@media (max-width: 640px) {
	.edittrace-panel {
		top: auto;
		left: 0;
		width: 100%;
		max-height: 70vh;
		border-left: 0;
		border-top: 1px solid var(--et-border);
		border-radius: 12px 12px 0 0;
	}
	.edittrace-toolbar {
		bottom: auto;
		top: 8px;
	}
}

.edittrace-panel__header {
	position: sticky;
	top: 0;
	z-index: 1;
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 14px;
	background: var(--et-bg);
	border-bottom: 1px solid var(--et-border);
}

.edittrace-panel__brand {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	font-weight: 700;
	font-size: 12px;
	letter-spacing: 0.02em;
	text-transform: uppercase;
	color: var(--et-text-2);
}

.edittrace-iconbtn {
	appearance: none;
	border: 0;
	background: transparent;
	color: var(--et-text-2);
	font-size: 20px;
	line-height: 1;
	width: 28px;
	height: 28px;
	border-radius: 6px;
	cursor: pointer;
}

.edittrace-iconbtn:hover, .edittrace-iconbtn:focus-visible {
	background: var(--et-bg-3);
	color: var(--et-text);
	outline: none;
}

.edittrace-panel__body {
	padding: 6px 14px 24px;
}

.edittrace-section {
	padding: 12px 0;
	border-bottom: 1px solid var(--et-border);
}

.edittrace-section:last-of-type {
	border-bottom: 0;
}

.edittrace-label {
	font-size: 10.5px;
	font-weight: 700;
	letter-spacing: 0.08em;
	text-transform: uppercase;
	color: var(--et-text-3);
	margin-bottom: 6px;
}

.edittrace-selected {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	font-size: 14px;
	font-weight: 600;
	color: var(--et-text);
	word-break: break-word;
}

.edittrace-tag {
	flex: none;
	padding: 1px 6px;
	border-radius: 4px;
	background: var(--et-bg-3);
	color: var(--et-text-2);
	font: 600 11px/18px var(--et-mono);
}

.edittrace-system {
	font-size: 16px;
	font-weight: 700;
	margin-bottom: 6px;
}

.edittrace-kv {
	display: grid;
	grid-template-columns: 110px 1fr;
	gap: 8px;
	padding: 3px 0;
	word-break: break-word;
}

.edittrace-kv__k {
	color: var(--et-text-3);
	font-size: 12px;
}

.edittrace-kv__v {
	color: var(--et-text);
}

.edittrace-kv__sub {
	display: block;
	color: var(--et-text-3);
	font: 11px/1.4 var(--et-mono);
}

.edittrace-crumbs {
	list-style: none;
	margin: 0;
	padding: 0;
}

.edittrace-crumb {
	position: relative;
	padding: 2px 0 2px 18px;
	color: var(--et-text-2);
}

.edittrace-crumb::before {
	content: "\u2192";
	position: absolute;
	left: 0;
	color: var(--et-text-3);
}

.edittrace-crumb:first-child {
	padding-left: 0;
	color: var(--et-text);
	font-weight: 600;
}

.edittrace-crumb:first-child::before {
	content: none;
}

.edittrace-crumb--current {
	color: var(--et-accent-2);
	font-weight: 600;
}

.edittrace-global {
	padding: 10px 12px;
	border-radius: var(--et-radius);
	background: rgba(242, 184, 75, 0.1);
	border: 1px solid rgba(242, 184, 75, 0.35);
	color: #f6d089;
}

.edittrace-global__title {
	font-size: 10.5px;
	font-weight: 700;
	letter-spacing: 0.08em;
	text-transform: uppercase;
	margin-bottom: 2px;
}

.edittrace-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.edittrace-btn {
	appearance: none;
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 8px 12px;
	border-radius: 6px;
	border: 1px solid var(--et-border);
	background: var(--et-bg-2);
	color: var(--et-text);
	font: 600 12.5px/1 var(--et-font);
	text-decoration: none;
	cursor: pointer;
}

.edittrace-btn:hover, .edittrace-btn:focus-visible {
	background: var(--et-bg-3);
	border-color: #3d434f;
	outline: none;
}

.edittrace-btn--primary {
	background: var(--et-accent);
	border-color: var(--et-accent);
	color: #fff;
}

.edittrace-btn--primary:hover, .edittrace-btn--primary:focus-visible {
	background: #3f7cf0;
	border-color: #3f7cf0;
}

.edittrace-btn--small {
	padding: 5px 9px;
	font-size: 11.5px;
}

.edittrace-btn--ghost {
	background: transparent;
}

.edittrace-confidence {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 999px;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.02em;
	background: var(--et-bg-3);
	color: var(--et-text-2);
}

.edittrace-confidence--exact { background: rgba(51, 196, 129, 0.15); color: var(--et-exact); }
.edittrace-confidence--high { background: rgba(79, 140, 255, 0.15); color: var(--et-high); }
.edittrace-confidence--possible { background: rgba(242, 184, 75, 0.15); color: var(--et-possible); }
.edittrace-confidence--unknown { background: rgba(138, 143, 154, 0.15); color: var(--et-unknown); }

.edittrace-reason {
	margin-top: 6px;
	font-size: 12px;
}

.edittrace-muted {
	color: var(--et-text-2);
	font-size: 12.5px;
}

.edittrace-headline {
	font-size: 15px;
	font-weight: 700;
	margin-bottom: 2px;
}

.edittrace-headline--warn {
	color: var(--et-warn);
}

.edittrace-candidates {
	list-style: none;
	margin: 0;
	padding: 0;
	counter-reset: et;
}

.edittrace-candidate {
	counter-increment: et;
	position: relative;
	padding: 10px 10px 10px 30px;
	margin-bottom: 8px;
	border: 1px solid var(--et-border);
	border-radius: var(--et-radius);
	background: var(--et-bg-2);
}

.edittrace-candidate::before {
	content: counter(et);
	position: absolute;
	left: 10px;
	top: 10px;
	width: 16px;
	height: 16px;
	border-radius: 4px;
	background: var(--et-bg-3);
	color: var(--et-text-2);
	font-size: 10px;
	font-weight: 700;
	line-height: 16px;
	text-align: center;
}

.edittrace-candidate__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.edittrace-candidate__name {
	font-weight: 600;
}

.edittrace-candidate__meta, .edittrace-candidate__reason {
	color: var(--et-text-2);
	font-size: 12px;
	margin-top: 2px;
}

.edittrace-candidate__global {
	display: inline-block;
	margin-top: 4px;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.06em;
	text-transform: uppercase;
	color: var(--et-warn);
}

.edittrace-candidate__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 8px;
}

.edittrace-details {
	margin-top: 8px;
	color: var(--et-text-2);
}

.edittrace-details > summary {
	cursor: pointer;
	font-size: 12px;
	font-weight: 600;
	color: var(--et-text-2);
	padding: 6px 0;
	list-style: none;
}

.edittrace-details > summary::-webkit-details-marker {
	display: none;
}

.edittrace-details > summary::before {
	content: "\u25B8";
	display: inline-block;
	width: 14px;
	color: var(--et-text-3);
}

.edittrace-details[open] > summary::before {
	content: "\u25BE";
}

.edittrace-technical {
	margin-top: 12px;
	border-top: 1px solid var(--et-border);
	padding-top: 4px;
}

.edittrace-technical__body {
	font: 11.5px/1.5 var(--et-mono);
	color: var(--et-text-2);
}

.edittrace-technical__body .edittrace-kv {
	grid-template-columns: 120px 1fr;
	font-family: var(--et-mono);
}

.edittrace-note {
	padding: 6px 8px;
	margin: 4px 0;
	border-left: 2px solid var(--et-warn);
	background: rgba(242, 184, 75, 0.06);
	color: var(--et-text-2);
}

.edittrace-loading {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 0;
	color: var(--et-text-2);
}

.edittrace-spinner {
	width: 14px;
	height: 14px;
	border: 2px solid var(--et-border);
	border-top-color: var(--et-accent);
	border-radius: 50%;
	animation: edittrace-spin 0.8s linear infinite;
}

@keyframes edittrace-spin {
	to { transform: rotate(360deg); }
}

.edittrace-alert {
	padding: 10px 12px;
	border-radius: var(--et-radius);
	margin: 10px 0;
}

.edittrace-alert--error {
	background: rgba(255, 107, 107, 0.1);
	border: 1px solid rgba(255, 107, 107, 0.35);
	color: #ffb0b0;
}

@media (prefers-reduced-motion: reduce) {
	.edittrace-highlight { transition: none; }
	.edittrace-spinner { animation: none; }
}
`;function H(){let a=window.EditTraceConfig;if(!a||!a.restUrl||!a.traceId)return;let e=new f(a,P);window.EditTrace=e;let t=document.querySelector("#wp-admin-bar-edittrace > a");t&&(t.addEventListener("click",r=>{r.preventDefault(),e.toggle()}),document.addEventListener("edittrace:activate",()=>{var r;return(r=t.parentElement)==null?void 0:r.classList.add("edittrace-admin-bar--active")}),document.addEventListener("edittrace:deactivate",()=>{var r;return(r=t.parentElement)==null?void 0:r.classList.remove("edittrace-admin-bar--active")})),window.location.hash==="#edittrace"&&e.activate()}document.readyState==="loading"?document.addEventListener("DOMContentLoaded",H):H();})();
