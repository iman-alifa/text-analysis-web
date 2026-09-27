{{--
    Halaman Pengaturan.

    Memakai @extends, bukan <x-app-layout>. Komponen itu merender
    `layouts.app`, tetapi layout tersebut menaruh isinya lewat @yield('content')
    dan bukan {{ $slot }} - jadi seluruh isi halaman ini dibuang diam-diam dan
    yang tampil hanya kerangkanya. Halaman profil karena itu tidak pernah benar-
    benar menampilkan formulirnya sejak awal.
--}}
@extends('layouts.app')

@section('content')
<div class="max-w-3xl space-y-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">Pengaturan</h1>
        <p class="mt-1 text-sm text-gray-600">Preferensi analisis, data akun, dan kata sandi.</p>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
        @include('profile.partials.update-preferences-form')
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
        @include('profile.partials.update-profile-information-form')
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
        @include('profile.partials.update-password-form')
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-red-100 p-6 sm:p-8">
        @include('profile.partials.delete-user-form')
    </div>
</div>
@endsection
