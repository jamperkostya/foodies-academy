<?php

// Messages of the validation rules the API uses; the site shows them under the form fields.
return [
    'required' => 'Заполните поле «:attribute».',
    'string' => 'Поле «:attribute» должно быть текстом.',
    'email' => 'Укажите корректный e-mail.',
    'integer' => 'Поле «:attribute» должно быть целым числом.',
    'in' => 'Недопустимое значение поля «:attribute».',
    'different' => 'Поля «:attribute» и «:other» должны различаться.',
    'min' => [
        'string' => 'Поле «:attribute» должно быть не короче :min символов.',
        'numeric' => 'Поле «:attribute» должно быть не меньше :min.',
    ],
    'max' => [
        'string' => 'Поле «:attribute» должно быть не длиннее :max символов.',
        'numeric' => 'Поле «:attribute» должно быть не больше :max.',
    ],

    'attributes' => [
        'name' => 'Имя',
        'email' => 'E-mail',
        'password' => 'Пароль',
        'currentPassword' => 'Текущий пароль',
        'firstName' => 'Имя',
        'lastName' => 'Фамилия',
        'description' => 'О себе',
        'text' => 'Текст',
        'rating' => 'Оценка',
        'q' => 'Запрос',
        'hits' => 'Найдено',
        'from' => 'Прежняя оценка',
        'to' => 'Новая оценка',
        'page' => 'Страница',
        'limit' => 'Количество',
    ],
];
