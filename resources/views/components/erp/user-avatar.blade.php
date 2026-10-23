@props(['userId', 'gender' => null, 'size' => 'sm', 'label' => null])

@php($avatarLabel = $label ? "{$label}، صورة افتراضية" : 'صورة افتراضية')

<img
    src="{{ \App\Support\DefaultAvatar::url((int) $userId, $gender) }}"
    alt="{{ $avatarLabel }}"
    title="{{ $avatarLabel }}"
    width="96"
    height="96"
    loading="lazy"
    decoding="async"
    @class(['size-9 rounded-full object-cover' => $size === 'sm', 'size-24 rounded-xl object-cover' => $size === 'lg'])
>
