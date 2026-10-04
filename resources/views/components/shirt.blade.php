@props(['color'=>'#c8bba8','polo'=>false])
<svg class="shirt-svg" viewBox="0 0 320 340" role="img" aria-label="Clothing illustration">
<defs><linearGradient id="cloth-{{ md5($color.($polo?'p':'t')) }}" x1="0" x2="1"><stop stop-color="{{ $color }}" offset="0"/><stop stop-color="{{ $color }}" offset=".6"/><stop stop-color="#000" stop-opacity=".17" offset="1"/></linearGradient></defs>
<ellipse cx="163" cy="305" rx="90" ry="10" fill="#18221f" opacity=".08"/>
<path d="M111 54 L77 70 L25 119 L69 162 L95 140 L94 294 Q160 309 226 294 L225 140 L251 162 L295 119 L243 70 L209 54 Q160 82 111 54Z" fill="url(#cloth-{{ md5($color.($polo?'p':'t')) }})" stroke="#000" stroke-opacity=".08" stroke-width="2"/>
<path d="M111 54 Q160 115 209 54" fill="none" stroke="#000" stroke-opacity=".12" stroke-width="7"/>
<path d="M111 59 Q160 93 209 59" fill="none" stroke="#fff" stroke-opacity=".2" stroke-width="3"/>
<path d="M95 140 L100 98 M225 140 L221 98 M104 285 Q162 298 216 285" fill="none" stroke="#000" stroke-opacity=".09" stroke-width="2"/>
@if($polo)<path d="M113 56 L144 95 L160 75 L176 95 L207 56 L186 66 L160 75 L133 66Z" fill="#000" opacity=".13"/><path d="M160 78 V123" stroke="#000" stroke-opacity=".2" stroke-width="3"/><circle cx="166" cy="96" r="2" fill="#eee"/><circle cx="166" cy="110" r="2" fill="#eee"/>@endif
<path d="M141 182 L147 254 M208 200 L212 276" fill="none" stroke="#fff" stroke-opacity=".07" stroke-width="5"/>
<rect x="151" y="70" width="17" height="8" rx="1" fill="#f3efe6" opacity=".7"/>
</svg>