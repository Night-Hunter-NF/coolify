@if ($links->count() > 0)
    <div class="group">
        <label tabindex="0" class="flex items-center gap-2 cursor-pointer hover:text-white"> Open Application
            <x-chevron-down />
        </label>

        <div class="absolute hidden group-hover:block">
            <ul tabindex="0"
                class="relative -ml-24 text-xs text-white normal-case rounded min-w-max menu bg-coolgray-200">
                @if ($links->count() > 0)
                    @foreach ($links as $link)
                        <li>
                            <a class="text-xs text-white rounded-none hover:no-underline hover:bg-coollabs hover:text-white"
                                target="_blank" href="{{ $link }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24"
                                    stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                    <path d="M9 15l6 -6" />
                                    <path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" />
                                    <path
                                        d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" />
                                </svg>{{ $link }}
                            </a>
                        </li>
                    @endforeach
                @endif
            </ul>
        </div>
    </div>
@endif
