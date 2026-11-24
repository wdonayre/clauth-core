<svg width="{{ $iconSize ?? 18 }}" height="{{ $iconSize ?? 18 }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">@switch($i)
@case('grid')<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>@break
@case('users')<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.8c1.9.7 3.1 2.5 3.5 5.2"/>@break
@case('box')<path d="M12 2.5l8.5 4.75v9.5L12 21.5l-8.5-4.75v-9.5zM3.5 7.25L12 12l8.5-4.75M12 12v9.5"/>@break
@case('org')<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M10 21v-4h4v4"/>@break
@case('list')<path d="M8 6h12M8 12h12M8 18h12"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>@break
@case('gear')<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>@break
@case('user')<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4.2-6 8-6s7 2 8 6"/>@break
@case('out')<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/>@break
@case('bell')<path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15zM10 20a2 2 0 0 0 4 0"/>@break
@case('plus')<path d="M12 5v14M5 12h14"/>@break
@case('home')<path d="M3 10.5L12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/>@break
@case('activity')<path d="M3 12h4l3-8 4 16 3-8h4"/>@break
@case('flag')<path d="M4 21V4h12l-1.5 4L16 12H4"/>@break
@case('key')<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3L20 3M16 7l3 3M14 9l2 2"/>@break
@case('sun')<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>@break
@case('moon')<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>@break
@case('monitor')<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>@break
@case('check')<path d="M5 12.5l4.5 4.5L19 7.5"/>@break
@case('search')<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>@break
@case('x')<path d="M6 6l12 12M18 6L6 18"/>@break
@case('down')<path d="M6 9l6 6 6-6"/>@break
@case('right')<path d="M9 6l6 6-6 6"/>@break
@case('left')<path d="M15 6l-6 6 6 6"/>@break
@case('more')<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>@break
@case('download')<path d="M12 4v11M7 10l5 5 5-5M4 20h16"/>@break
@case('mail')<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5L12 13l8.5-6.5"/>@break
@case('shield')<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>@break
@case('lock')<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>@break
@case('link')<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>@break
@case('copy')<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a1 1 0 0 1 1-1h10"/>@break
@case('trash')<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>@break
@case('edit')<path d="M4 20h4L19 9l-4-4L4 16zM14 6l4 4"/>@break
@case('refresh')<path d="M20 11a8 8 0 0 0-14.6-4.5L4 8M4 4v4h4M4 13a8 8 0 0 0 14.6 4.5L20 16M20 20v-4h-4"/>@break
@case('external')<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>@break
@case('phone')<rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M11 18h2"/>@break
@case('globe')<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 3.5 5.5 3.5 9s-1 6.5-3.5 9c-2.5-2.5-3.5-5.5-3.5-9s1-6.5 3.5-9z"/>@break
@case('code')<path d="M8 8l-4 4 4 4M16 8l4 4-4 4M14 5l-4 14"/>@break
@case('team')<circle cx="8" cy="9" r="3"/><circle cx="16" cy="9" r="3"/><path d="M2.5 19c.6-3 2.8-4.5 5.5-4.5s4.9 1.5 5.5 4.5M10.5 19c.6-3 2.8-4.5 5.5-4.5s4.9 1.5 5.5 4.5"/>@break
@case('bot')<rect x="5" y="8" width="14" height="11" rx="3"/><path d="M12 4v4M9 13h.01M15 13h.01M3 13v2M21 13v2"/>@break
@case('palette')<path d="M12 3a9 9 0 0 0 0 18c1.4 0 2-1 2-2s-1-1.5-1-2.5S14 15 15 15h2a4 4 0 0 0 4-4c0-4.4-4-8-9-8z"/><circle cx="7.5" cy="11.5" r="1"/><circle cx="10" cy="7.5" r="1"/><circle cx="15" cy="7.5" r="1"/>@break
@case('info')<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>@break
@case('alert')<path d="M12 3l9.5 17H2.5z"/><path d="M12 10v4M12 17h.01"/>@break
@endswitch</svg>
