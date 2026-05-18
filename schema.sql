-- =====================================================
-- BISU IGE Aquaculture System - New Schema
-- Run this ONCE to set up the database.
-- WARNING: This drops all existing tables!
-- =====================================================

BEGIN;

DROP TABLE IF EXISTS public.harvest_consumption CASCADE;
DROP TABLE IF EXISTS public.return_requests CASCADE;
DROP TABLE IF EXISTS public.deduction_history CASCADE;
DROP TABLE IF EXISTS public.salary_deductions CASCADE;
DROP TABLE IF EXISTS public.order_items CASCADE;
DROP TABLE IF EXISTS public.notifications CASCADE;
DROP TABLE IF EXISTS public.harvest CASCADE;
DROP TABLE IF EXISTS public.fish_products CASCADE;
DROP TABLE IF EXISTS public.orders CASCADE;
DROP TABLE IF EXISTS public.users CASCADE;

CREATE TABLE public.users (
    user_id serial PRIMARY KEY,
    employee_id varchar(50) UNIQUE,
    full_name varchar(150),
    department varchar(100),
    position varchar(100),
    contact_number varchar(50),
    email varchar(150),
    role varchar(50),
    hashed_password varchar,
    created_at timestamp default current_timestamp,
    updated_at timestamp default current_timestamp
);

CREATE TABLE public.fish_products (
    product_id serial PRIMARY KEY,
    fish_name varchar(150),
    description text,
    price_per_kg numeric NOT NULL,
    created_at timestamp default current_timestamp,
    updated_at timestamp default current_timestamp
);

CREATE TABLE public.harvest (
    harvest_id serial PRIMARY KEY,
    fish_product_id integer NOT NULL,
    batch_no varchar(50),
    harvest_date date,
    location varchar(150),
    total_quantity numeric,
    remaining_quantity numeric,
    status varchar(50),
    created_at timestamp default current_timestamp,
    updated_at timestamp default current_timestamp,
    CONSTRAINT harvest_fish_product_fk
    FOREIGN KEY (fish_product_id) REFERENCES public.fish_products(product_id) ON DELETE RESTRICT
);

CREATE INDEX idx_harvest_product ON public.harvest(fish_product_id);

CREATE TABLE public.orders (
    order_id serial PRIMARY KEY,
    user_id integer,
    order_status varchar(50) default 'pending',
    payment_method varchar(50) default 'salary_deduction',
    total_amount numeric,
    order_date timestamp default current_timestamp,
    confirmed_at timestamp,
    claimed_at timestamp,
    cancelled_at timestamp,
    remarks text,
    created_at timestamp default current_timestamp,
    updated_at timestamp default current_timestamp,
    CONSTRAINT orders_user_fk FOREIGN KEY (user_id) REFERENCES public.users(user_id)
);

CREATE TABLE public.order_items (
    order_item_id serial PRIMARY KEY,
    order_id integer NOT NULL,
    product_id integer NOT NULL,
    harvest_id integer,
    quantity numeric NOT NULL,
    price_per_kg numeric NOT NULL,
    subtotal numeric,
    created_at timestamp default current_timestamp,
    updated_at timestamp default current_timestamp,
    CONSTRAINT order_items_unique UNIQUE (order_id, product_id),
    CONSTRAINT order_items_order_fk FOREIGN KEY (order_id) REFERENCES public.orders(order_id) ON DELETE CASCADE,
    CONSTRAINT order_items_product_fk FOREIGN KEY (product_id) REFERENCES public.fish_products(product_id),
    CONSTRAINT order_items_harvest_fk FOREIGN KEY (harvest_id) REFERENCES public.harvest(harvest_id)
);

CREATE TABLE public.salary_deductions (
    deduction_id serial PRIMARY KEY,
    order_id integer,
    user_id integer,
    total_amount numeric,
    amount_paid numeric default 0,
    remaining_balance numeric,
    deduction_status varchar(50),
    deduction_start_date date,
    deduction_end_date date,
    remarks text,
    created_at timestamp default current_timestamp,
    updated_at timestamp default current_timestamp,
    completed_at timestamp,
    CONSTRAINT salary_deductions_order_fk FOREIGN KEY (order_id) REFERENCES public.orders(order_id),
    CONSTRAINT salary_deductions_user_fk FOREIGN KEY (user_id) REFERENCES public.users(user_id)
);

CREATE TABLE public.harvest_consumption (
    id serial PRIMARY KEY,
    harvest_id integer NOT NULL,
    order_item_id integer NOT NULL,
    quantity_used numeric NOT NULL,
    created_at timestamp default current_timestamp,
    CONSTRAINT harvest_consumption_harvest_fk FOREIGN KEY (harvest_id) REFERENCES public.harvest(harvest_id),
    CONSTRAINT harvest_consumption_order_item_fk FOREIGN KEY (order_item_id) REFERENCES public.order_items(order_item_id)
);

CREATE TABLE public.notifications (
    notification_id serial PRIMARY KEY,
    user_id integer,
    title varchar(150),
    message text,
    type varchar(50),
    is_read boolean default false,
    created_at timestamp default current_timestamp,
    CONSTRAINT notifications_user_fk FOREIGN KEY (user_id) REFERENCES public.users(user_id)
);

CREATE TABLE public.return_requests (
    return_id serial PRIMARY KEY,
    order_id integer NOT NULL,
    user_id integer NOT NULL,
    deduction_id integer,
    request_date timestamp default now(),
    return_reason varchar(50) NOT NULL,
    return_description text,
    return_quantity numeric(10,2) default 0,
    return_amount numeric(10,2) default 0,
    product_id integer,
    original_quantity numeric(10,2),
    original_price numeric(10,2),
    return_status varchar(20) default 'pending',
    processed_by integer,
    processed_date timestamp,
    processed_remarks text,
    refund_method varchar(20),
    refund_amount numeric(10,2) default 0,
    refund_date timestamp,
    image_path varchar(255),
    created_at timestamp default now(),
    updated_at timestamp default now(),
    FOREIGN KEY (order_id) REFERENCES public.orders(order_id),
    FOREIGN KEY (user_id) REFERENCES public.users(user_id),
    FOREIGN KEY (deduction_id) REFERENCES public.salary_deductions(deduction_id),
    FOREIGN KEY (product_id) REFERENCES public.fish_products(product_id),
    FOREIGN KEY (processed_by) REFERENCES public.users(user_id)
);

CREATE INDEX idx_return_order ON public.return_requests(order_id);
CREATE INDEX idx_return_user ON public.return_requests(user_id);

CREATE TABLE public.deduction_history (
    history_id serial PRIMARY KEY,
    deduction_id integer,
    amount_deducted numeric,
    deduction_date date,
    payroll_period varchar(50),
    remarks text,
    created_at timestamp default current_timestamp,
    FOREIGN KEY (deduction_id) REFERENCES public.salary_deductions(deduction_id)
);

COMMIT;
